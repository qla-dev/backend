<?php

namespace App\Services\Accounting;

use App\Models\Document;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AccountingInvoices
{
    public function __construct(private AccountingLedger $ledger) {}

    public function save(int $companyId, int $actor, array $data, ?int $id = null): Invoice
    {
        return DB::transaction(function () use ($companyId, $actor, $data, $id) {
            $settings = DB::table('accounting_settings')->where('company_id', $companyId)->lockForUpdate()->first();
            $this->ledger->require($settings !== null, 'Configure company accounting first.');
            $invoice = $id ? Invoice::where('company_id', $companyId)->where('accounting_managed', true)->lockForUpdate()->findOrFail($id) : new Invoice;
            if ($id) {
                $this->ledger->require(in_array($invoice->approval_status, ['draft', 'rejected'], true) && $invoice->issuance_status === 'draft', 'Approved or issued content is protected.');
                $this->ledger->require((int) ($data['revision'] ?? 0) === (int) $invoice->revision, 'Invoice changed. Reload before saving.');
                $this->ledger->require(! $invoice->corrects_invoice_id || ! array_key_exists('items', $data), 'A full corrective invoice preserves the source line amounts.');
            }
            $fields = ['direction', 'partner_id', 'partner_name', 'partner_tax_number', 'supplier_number', 'customer_user_id', 'issued_at', 'due_at',
                'event_date', 'tax_date', 'posting_date', 'currency', 'exchange_rate', 'exchange_date', 'exchange_source', 'exchange_reason'];
            $invoice->fill(array_intersect_key($data, array_flip($fields)));
            if ($invoice->partner_id) {
                $partner = DB::table('accounting_partners')->where('company_id', $companyId)->where('id', $invoice->partner_id)->first();
                $this->ledger->require($partner !== null, 'Partner belongs to another company.');
                $invoice->partner_name = $partner->name;
                $invoice->partner_tax_number = $partner->tax_number;
            }
            $invoice->company_id = $companyId;
            $invoice->accounting_managed = true;
            $invoice->base_currency = $settings->base_currency;
            if (! $id) {
                $invoice->number = 'DRAFT-'.Str::uuid();
                $invoice->issued_by_user_id = $actor;
                $invoice->approval_status = 'draft';
                $invoice->issuance_status = 'draft';
                $invoice->direction = $data['direction'] ?? 'incoming';
                $invoice->currency = $data['currency'] ?? $settings->base_currency;
            } else {
                $invoice->revision++;
            }
            if ($invoice->currency === $settings->base_currency) {
                $invoice->exchange_rate = '1';
                $invoice->exchange_source = 'base_currency';
                $invoice->exchange_date = $invoice->event_date;
            }
            $invoice->save();
            if (array_key_exists('items', $data)) {
                // Only draft rows can be replaced; no posted event is ever deleted.
                DB::table('accounting_cost_allocations')->whereIn('invoice_item_id', $invoice->items()->pluck('id'))->delete();
                $invoice->items()->delete();
                $subtotal = '0.00';
                $taxTotal = '0.00';
                $unknown = false;
                foreach ($data['items'] as $item) {
                    $quantity = Decimal::value($item['quantity'], 4);
                    $price = Decimal::value($item['unit_price'], 4);
                    $this->ledger->require(bccomp($quantity, '0', 4) > 0 && bccomp($price, '0', 4) >= 0, 'Quantity must be positive and price nonnegative.');
                    $net = Decimal::round(bcmul($quantity, $price, 8));
                    $rule = ! empty($item['tax_rule_id']) ? DB::table('accounting_tax_rules')->where('company_id', $companyId)->where('id', $item['tax_rule_id'])->first() : null;
                    $this->ledger->require(empty($item['tax_rule_id']) || $rule !== null, 'Tax rule belongs to another company.');
                    $tax = $rule && $rule->rate !== null ? Decimal::round(bcdiv(bcmul($net, (string) $rule->rate, 8), '100', 8)) : null;
                    $this->ledger->require(empty($item['account_id']) || DB::table('accounting_accounts')->where('company_id', $companyId)->where('id', $item['account_id'])->exists(), 'Account belongs to another company.');
                    if (! empty($item['workspace_id'])) {
                        $this->workspace($companyId, (int) $item['workspace_id'], $invoice->direction);
                    }
                    $row = $invoice->items()->create(['description' => $item['description'], 'quantity' => $quantity, 'unit_price' => $price,
                        'total' => $net, 'tax_amount' => $tax, 'tax_rule_id' => $rule?->id, 'tax_treatment' => $rule?->treatment ?? 'unknown',
                        'tax_rate' => $rule?->rate, 'deductible_percent' => $rule?->deductible_percent, 'account_id' => $item['account_id'] ?? null,
                        'workspace_id' => $item['workspace_id'] ?? null]);
                    $subtotal = bcadd($subtotal, $net, 2);
                    $taxTotal = bcadd($taxTotal, $tax ?? '0', 2);
                    $unknown = $unknown || $tax === null;
                    if ($invoice->direction === 'incoming') {
                        $allocations = $item['allocations'] ?? [['workspace_id' => $item['workspace_id'] ?? null, 'amount' => $net]];
                        $this->ledger->require(bccomp(Decimal::sum(array_column($allocations, 'amount')), $net, 2) === 0, 'Cost allocations must equal the line net amount.');
                        foreach ($allocations as $allocation) {
                            $amount = Decimal::value($allocation['amount']);
                            $this->ledger->require(bccomp($amount, '0', 2) >= 0, 'Cost allocation cannot be negative.');
                            if (! empty($allocation['workspace_id'])) {
                                $this->workspace($companyId, (int) $allocation['workspace_id'], 'incoming');
                            }
                            if (! empty($allocation['estimate_id'])) {
                                $estimate = DB::table('accounting_cost_estimates')->where('company_id', $companyId)->where('id', $allocation['estimate_id'])->first();
                                $this->ledger->require($estimate && (int) $estimate->workspace_id === (int) ($allocation['workspace_id'] ?? 0)
                                    && $estimate->currency === $invoice->currency, 'Estimate must belong to the same job and currency.');
                                $this->ledger->require(! DB::table('accounting_cost_allocations')->where('estimate_id', $estimate->id)->exists(), 'Estimate is already replaced by another actual cost.');
                            }
                            DB::table('accounting_cost_allocations')->insert(['company_id' => $companyId, 'invoice_item_id' => $row->id,
                                'workspace_id' => $allocation['workspace_id'] ?? null, 'amount' => $amount, 'estimate_id' => $allocation['estimate_id'] ?? null,
                                'created_at' => now(), 'updated_at' => now()]);
                        }
                    }
                }
                // Unknown tax is explicitly represented by unknown treatment, not a zero-rate rule.
                $invoice->subtotal = $subtotal;
                $invoice->tax = $taxTotal;
                $invoice->total = bcadd($subtotal, $taxTotal, 2);
                $invoice->save();
                $workspaceIds = $invoice->items()->whereNotNull('workspace_id')->pluck('workspace_id')->unique();
                $invoice->load_id = $workspaceIds->count() === 1 ? DB::table('shipment_workspace')->where('id', $workspaceIds->first())->value('load_id') : null;
                $invoice->save();
            }
            if (array_key_exists('document_ids', $data)) {
                foreach ($data['document_ids'] as $documentId) {
                    $document = DB::table('documents')->where('id', $documentId)->first();
                    $this->ledger->require($document !== null, 'Document not found.');
                    $uploader = (int) $document->uploaded_by_user_id;
                    $companyOwner = (int) DB::table('companies')->where('id', $companyId)->value('owner_user_id');
                    $this->ledger->require($uploader === $companyOwner || DB::table('company_user')->where('company_id', $companyId)
                        ->where('user_id', $uploader)->where('status', 'active')->exists(), 'Document belongs to another company.');
                }
                DB::table('accounting_invoice_documents')->where('invoice_id', $invoice->id)->delete();
                foreach (array_unique($data['document_ids']) as $documentId) {
                    DB::table('accounting_invoice_documents')->insert(['invoice_id' => $invoice->id, 'document_id' => $documentId]);
                }
            }
            $this->ledger->audit($companyId, $actor, 'invoice', $invoice->id, $id ? 'draft_updated' : 'draft_created', ['revision' => $invoice->revision]);

            return $invoice->fresh('items');
        });
    }

    public function transition(int $companyId, int $actor, int $id, string $action, array $data): Invoice
    {
        return DB::transaction(function () use ($companyId, $actor, $id, $action, $data) {
            $settings = DB::table('accounting_settings')->where('company_id', $companyId)->lockForUpdate()->first();
            $invoice = Invoice::where('company_id', $companyId)->where('accounting_managed', true)->lockForUpdate()->findOrFail($id);
            $entry = DB::table('accounting_entries')->where('company_id', $companyId)->where('event_key', 'invoice:'.$id)->first();
            if ($action === 'post' && $entry) {
                return $invoice->fresh('items');
            }
            if ($action === 'submit') {
                $this->ledger->require(in_array($invoice->approval_status, ['draft', 'rejected'], true), 'Only a draft can be submitted.');
                $this->validateReady($invoice);
                $invoice->approval_status = 'pending';
            } elseif ($action === 'approve' || $action === 'reject') {
                $this->ledger->require($invoice->approval_status === 'pending', 'Invoice is not awaiting approval.');
                if ($action === 'approve') {
                    $this->validateReady($invoice);
                }
                $invoice->approval_status = $action === 'approve' ? 'approved' : 'rejected';
                $invoice->approved_by = $action === 'approve' ? $actor : null;
            } elseif ($action === 'issue') {
                $this->ledger->require($invoice->direction === 'outgoing' && $invoice->approval_status === 'approved' && $invoice->issuance_status === 'draft', 'Only an approved outgoing draft can be issued.');
                $this->validateReady($invoice);
                // Company-qualified namespace preserves compatibility with legacy global unique numbers.
                $invoice->number = $settings->invoice_prefix.'-'.$companyId.'-'.sprintf('%06d', $settings->next_invoice_number);
                DB::table('accounting_settings')->where('company_id', $companyId)->increment('next_invoice_number');
                $invoice->issuance_status = 'issued';
                $invoice->status = 'issued';
                $this->assertUnbilled($invoice);
                $invoice->issued_snapshot = [
                    'seller' => (array) DB::table('companies')->where('id', $companyId)->first(),
                    'buyer' => (array) DB::table('accounting_partners')->where('company_id', $companyId)->where('id', $invoice->partner_id)->first(),
                    'number' => $invoice->number, 'currency' => $invoice->currency,
                    'issued_at' => $invoice->issued_at?->format('Y-m-d'), 'due_at' => $invoice->due_at?->format('Y-m-d'),
                    'subtotal' => $invoice->subtotal, 'tax' => $invoice->tax, 'total' => $invoice->total,
                    'corrects_invoice_id' => $invoice->corrects_invoice_id, 'items' => $invoice->items()->get()->toArray(),
                ];
                $invoice->save();
                $html = view('documents.accounting-invoice', ['snapshot' => $invoice->issued_snapshot])->render();
                $document = Document::create(['uploaded_by_user_id' => $actor, 'type' => 'INVOICE',
                    'name' => $invoice->number.'.html', 'reference' => $invoice->number,
                    'path' => 'accounting-issued/'.$invoice->id.'.html', 'mime_type' => 'text/html', 'size_bytes' => strlen($html)]);
                DB::table('accounting_invoice_documents')->insert(['invoice_id' => $id, 'document_id' => $document->id]);
                DB::table('accounting_issued_documents')->insert(['company_id' => $companyId, 'invoice_id' => $id, 'document_id' => $document->id,
                    'html' => $html, 'created_at' => now(), 'updated_at' => now()]);
            } elseif ($action === 'post') {
                $this->ledger->require($invoice->approval_status === 'approved', 'Invoice must be approved.');
                $this->ledger->require($invoice->direction === 'incoming' || $invoice->issuance_status === 'issued', 'Issue the outgoing invoice first.');
                $this->validateReady($invoice);
                $lines = $this->postingLines($invoice, $data);
                $source = $invoice->corrects_invoice_id ? DB::table('accounting_entries')->where('company_id', $companyId)->where('event_key', 'invoice:'.$invoice->corrects_invoice_id)->first() : null;
                if ($invoice->corrects_invoice_id) {
                    $this->ledger->require($source && ! DB::table('accounting_entries')->where('reverses_entry_id', $source->id)->exists(), 'Source invoice is missing or already corrected.');
                }
                $this->ledger->post($companyId, $actor, ['event_key' => 'invoice:'.$id, 'posting_date' => $invoice->posting_date,
                    'description' => $invoice->supplier_number ?: $invoice->number, 'invoice_id' => $id, 'lines' => $lines, 'reverses_entry_id' => $source?->id]);
            } else {
                $this->ledger->require(false, 'Unsupported invoice action.');
            }
            $invoice->revision++;
            $invoice->save();
            $this->ledger->audit($companyId, $actor, 'invoice', $id, $action, ['revision' => $invoice->revision]);

            return $invoice->fresh('items');
        });
    }

    public function validateReady(Invoice $invoice): void
    {
        foreach (['partner_id', 'partner_name', 'issued_at', 'due_at', 'event_date', 'tax_date', 'posting_date', 'currency', 'exchange_rate', 'exchange_date', 'exchange_source'] as $field) {
            $this->ledger->require(filled($invoice->{$field}), 'Required before approval: '.$field);
        }
        $this->ledger->require($invoice->direction !== 'incoming' || filled($invoice->supplier_number), 'Supplier invoice number is required.');
        $this->ledger->require(bccomp((string) $invoice->exchange_rate, '0', 8) > 0, 'Exchange rate must be positive.');
        $this->ledger->require($invoice->currency === $invoice->base_currency || filled($invoice->exchange_reason), 'A manually entered exchange rate requires a reason.');
        $items = $invoice->items()->get();
        $this->ledger->require($items->isNotEmpty(), 'Invoice requires at least one line.');
        foreach ($items as $item) {
            $rule = DB::table('accounting_tax_rules')->where('company_id', $invoice->company_id)->where('id', $item->tax_rule_id)->first();
            $settings = DB::table('accounting_settings')->where('company_id', $invoice->company_id)->first();
            $taxDate = $invoice->corrects_invoice_id ? Invoice::where('company_id', $invoice->company_id)->findOrFail($invoice->corrects_invoice_id)->tax_date : $invoice->tax_date;
            $this->ledger->require($rule && $rule->verification_status === 'approved' && $rule->approved_by && $rule->rate !== null
                && in_array($rule->jurisdiction, [$settings->jurisdiction, 'BA'], true) && $rule->tax_type === 'vat'
                && $rule->effective_from <= $taxDate && $rule->applies_from <= $taxDate
                && (! $rule->effective_until || $rule->effective_until >= $taxDate), 'Tax treatment requires an approved rule valid on the tax date.');
            $this->ledger->require($invoice->direction !== 'incoming' || $rule->deductible_percent !== null, 'VAT deduction must be specified for incoming invoices.');
            $this->ledger->require($item->account_id && DB::table('accounting_accounts')->where('company_id', $invoice->company_id)
                ->where('id', $item->account_id)->where('active', true)->whereNotNull('approved_by')->exists(), 'Line account must be approved.');
        }
    }

    public function postingLines(Invoice $invoice, array $data): array
    {
        if ($invoice->corrects_invoice_id) {
            $source = DB::table('accounting_entries')->where('company_id', $invoice->company_id)->where('event_key', 'invoice:'.$invoice->corrects_invoice_id)->first();
            $this->ledger->require($source !== null, 'Corrective invoice requires a posted source invoice.');

            return DB::table('accounting_entry_lines')->where('entry_id', $source->id)->get()->map(fn ($l) => [
                'account_id' => $l->account_id, 'debit' => $l->credit, 'credit' => $l->debit, 'tax_rule_id' => $l->tax_rule_id,
                'invoice_item_id' => $l->invoice_item_id,
            ])->all();
        }
        $incoming = $invoice->direction === 'incoming';
        $lines = [];
        $sum = '0.00';
        foreach ($invoice->items()->get() as $item) {
            $net = Decimal::convert((string) $item->total, (string) $invoice->exchange_rate);
            $vat = Decimal::convert((string) $item->tax_amount, (string) $invoice->exchange_rate);
            $deductible = $incoming ? Decimal::round(bcdiv(bcmul($vat, (string) $item->deductible_percent, 8), '100', 8)) : $vat;
            $cost = $incoming ? bcadd($net, bcsub($vat, $deductible, 2), 2) : $net;
            if (bccomp($cost, '0', 2) > 0) {
                $lines[] = ['account_id' => $item->account_id, 'debit' => $incoming ? $cost : '0', 'credit' => $incoming ? '0' : $cost, 'tax_rule_id' => $item->tax_rule_id, 'invoice_item_id' => $item->id];
            }
            if (bccomp($deductible, '0', 2) > 0) {
                $this->ledger->require(! empty($data['vat_account_id']), 'VAT account required.');
                $lines[] = ['account_id' => $data['vat_account_id'], 'debit' => $incoming ? $deductible : '0', 'credit' => $incoming ? '0' : $deductible, 'tax_rule_id' => $item->tax_rule_id, 'invoice_item_id' => $item->id];
            }
            $sum = bcadd($sum, bcadd($net, $vat, 2), 2);
        }
        $this->ledger->require(! empty($data['control_account_id']), 'Receivable/payable control account required.');
        $lines[] = ['account_id' => $data['control_account_id'], 'debit' => $incoming ? '0' : $sum, 'credit' => $incoming ? $sum : '0'];

        return $lines;
    }

    public function workspace(int $companyId, int $id, string $direction): object
    {
        $job = DB::table('shipment_workspace')->where('id', $id)->first();
        $this->ledger->require($job && ($direction === 'outgoing' ? (int) $job->provider_company_id === $companyId
            : ((int) $job->provider_company_id === $companyId || DB::table('loads')->where('id', $job->load_id)->where('company_id', $companyId)->exists())), 'Job does not belong to this company / service provider.');

        return $job;
    }

    private function assertUnbilled(Invoice $invoice): void
    {
        if ($invoice->corrects_invoice_id) {
            return;
        }
        foreach ($invoice->items()->whereNotNull('workspace_id')->get() as $item) {
            $this->ledger->require(! DB::table('invoice_items')->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
                ->where('invoices.company_id', $invoice->company_id)->where('invoices.direction', 'outgoing')
                ->where('invoices.issuance_status', 'issued')->whereNull('invoices.corrects_invoice_id')->where('invoices.id', '!=', $invoice->id)
                ->where('invoice_items.workspace_id', $item->workspace_id)->exists(), 'Job already has an issued invoice. Use a corrective document.');
        }
    }

    public function corrective(int $companyId, int $actor, int $id, string $date, string $reason): Invoice
    {
        return DB::transaction(function () use ($companyId, $actor, $id, $date, $reason) {
            DB::table('accounting_settings')->where('company_id', $companyId)->lockForUpdate()->first();
            $source = Invoice::where('company_id', $companyId)->where('accounting_managed', true)->findOrFail($id);
            $this->ledger->require(! $source->corrects_invoice_id && DB::table('accounting_entries')->where('company_id', $companyId)->where('event_key', 'invoice:'.$id)->exists(), 'Only an original posted invoice can be corrected.');
            $this->ledger->require(! Invoice::where('company_id', $companyId)->where('corrects_invoice_id', $id)->exists(), 'A linked corrective invoice already exists.');
            $copy = $source->replicate(['number', 'issued_snapshot', 'approved_by', 'paid_at', 'corrects_invoice_id']);
            $copy->number = 'DRAFT-'.Str::uuid();
            $copy->corrects_invoice_id = $id;
            $copy->issued_by_user_id = $actor;
            $copy->approval_status = 'draft';
            $copy->issuance_status = 'draft';
            $copy->status = 'draft';
            $copy->revision = 1;
            $copy->posting_date = $date;
            $copy->tax_date = $date;
            $copy->issued_at = $date;
            $copy->due_at = $date;
            $copy->save();
            foreach ($source->items()->get() as $item) {
                $line = $item->replicate();
                $line->invoice_id = $copy->id;
                $line->workspace_id = null;
                $line->save();
            }
            $this->ledger->audit($companyId, $actor, 'invoice', $copy->id, 'corrective_draft_created', ['source_invoice_id' => $id, 'reason' => $reason]);

            return $copy->fresh('items');
        });
    }
}
