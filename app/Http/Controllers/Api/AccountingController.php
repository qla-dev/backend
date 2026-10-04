<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Accounting\AccountingAccess;
use App\Services\Accounting\AccountingInvoices;
use App\Services\Accounting\AccountingLedger;
use App\Services\Accounting\AccountingPayments;
use App\Services\Accounting\Decimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

final class AccountingController extends Controller
{
    public function __construct(private AccountingAccess $access, private AccountingLedger $ledger,
        private AccountingInvoices $invoices, private AccountingPayments $payments) {}

    private function company(Request $r, string $ability = 'view'): int
    {
        $id = (int) $r->route('company');
        $this->access->authorize($r->user(), $id, $ability);

        return $id;
    }

    private function response(mixed $data, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $data, 'message' => 'Accounting operation completed.', 'errors' => [], 'meta' => []], $status);
    }

    public function companies(Request $r): JsonResponse
    {
        return $this->response(Company::query()->where(function ($q) use ($r) {
            $q->where('owner_user_id', $r->user()->id)->orWhereHas('users', fn ($u) => $u->where('users.id', $r->user()->id)->where('company_user.status', 'active'));
        })->get(['id', 'name', 'owner_user_id']));
    }

    public function context(Request $r): JsonResponse
    {
        $id = (int) $r->route('company');
        $company = $this->access->member($r->user(), $id);

        return $this->response(['company' => $company->only(['id', 'name', 'owner_user_id']),
            'abilities' => $this->access->abilities($r->user(), $id), 'available_abilities' => AccountingAccess::ABILITIES,
            'can_manage_permissions' => (int) $company->owner_user_id === (int) $r->user()->id,
            'user_id' => $r->user()->id,
            'configured' => DB::table('accounting_settings')->where('company_id', $id)->exists()]);
    }

    public function permissions(Request $r): JsonResponse
    {
        $id = (int) $r->route('company');
        $company = $this->access->member($r->user(), $id);
        abort_unless((int) $company->owner_user_id === (int) $r->user()->id, 403);
        $data = $r->validate(['user_id' => ['required', 'integer', 'exists:users,id'], 'abilities' => ['required', 'array'],
            'abilities.*' => [Rule::in(AccountingAccess::ABILITIES)]]);
        $user = User::findOrFail($data['user_id']);
        $this->access->member($user, $id);
        DB::transaction(function () use ($id, $r, $data) {
            Company::whereKey($id)->lockForUpdate()->first();
            DB::table('accounting_permissions')->updateOrInsert(['company_id' => $id, 'user_id' => $data['user_id']],
                ['abilities' => json_encode(array_values(array_unique($data['abilities']))), 'granted_by' => $r->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->ledger->audit($id, $r->user()->id, 'permissions', $data['user_id'], 'permissions_changed', $data);
        });

        return $this->response($data);
    }

    public function overview(Request $r): JsonResponse
    {
        $id = $this->company($r);
        $settings = DB::table('accounting_settings')->where('company_id', $id)->first();
        $accounts = DB::table('accounting_accounts')->where('company_id', $id)->orderBy('code')->get();
        $periods = DB::table('accounting_periods')->where('company_id', $id)->orderByDesc('starts_on')->get();
        $rules = DB::table('accounting_tax_rules')->where('company_id', $id)->orderByDesc('id')->get();
        $jobs = DB::table('shipment_workspace')->where('provider_company_id', $id)->get();
        $invoiceRows = Invoice::where('company_id', $id)->where('accounting_managed', true)->with('items')->orderByDesc('id')->get();
        foreach ($invoiceRows as $invoice) {
            $paid = $this->payments->allocated('invoice_id', $invoice->id);
            $entry = DB::table('accounting_entries')->where('company_id', $id)->where('event_key', 'invoice:'.$invoice->id)->first();
            $reversed = $entry && DB::table('accounting_entries')->where('company_id', $id)->where('reverses_entry_id', $entry->id)->exists();
            $invoice->setAttribute('posting_status', $reversed ? 'reversed' : ($entry ? 'posted' : 'unposted'));
            $invoice->setAttribute('payment_status', bccomp($paid, '0', 2) === 0 ? 'unpaid' : (bccomp($paid, (string) $invoice->total, 2) >= 0 ? 'paid' : 'partial'));
            $invoice->setAttribute('paid_amount', $paid);
            $invoice->setAttribute('remaining_amount', bcsub((string) $invoice->total, $paid, 2));
            if ($reversed) {
                $invoice->setAttribute('remaining_amount', bcsub('0', $paid, 2));
            }
            if ($invoice->corrects_invoice_id) {
                $invoice->setAttribute('remaining_amount', '0.00');
            }
            $invoice->setAttribute('tax_unknown', $invoice->items->contains(fn ($i) => $i->tax_treatment === 'unknown'));
            $invoice->setAttribute('duplicate_warning', $invoice->direction === 'incoming' && $invoice->supplier_number && Invoice::where('company_id', $id)
                ->where('partner_id', $invoice->partner_id)->where('supplier_number', $invoice->supplier_number)->where('id', '!=', $invoice->id)->whereNull('corrects_invoice_id')->exists());
            $invoice->setAttribute('allocations', DB::table('accounting_cost_allocations')->whereIn('invoice_item_id', $invoice->items->pluck('id'))->get());
            $invoice->setAttribute('documents', DB::table('accounting_invoice_documents')->join('documents', 'documents.id', '=', 'accounting_invoice_documents.document_id')
                ->where('invoice_id', $invoice->id)->get(['documents.id', 'documents.name', 'documents.mime_type']));
        }
        $entries = DB::table('accounting_entries')->where('company_id', $id)->orderByDesc('id')->get();
        foreach ($entries as $entry) {
            $entry->lines = DB::table('accounting_entry_lines')->where('entry_id', $entry->id)->get();
        }
        $bank = DB::table('accounting_bank_transactions')->where('company_id', $id)->orderByDesc('id')->get();
        foreach ($bank as $b) {
            $b->allocated_amount = $this->payments->allocated('bank_transaction_id', $b->id);
        }
        $audit = DB::table('accounting_audit_events')->leftJoin('users', 'users.id', '=', 'accounting_audit_events.user_id')
            ->where('accounting_audit_events.company_id', $id)->orderByDesc('accounting_audit_events.id')->limit(300)
            ->get(['accounting_audit_events.*', 'users.name as user_name']);
        $balances = DB::table('accounting_entry_lines')->join('accounting_entries', 'accounting_entries.id', '=', 'accounting_entry_lines.entry_id')
            ->where('accounting_entries.company_id', $id)->selectRaw('account_id, SUM(debit) as debit, SUM(credit) as credit')->groupBy('account_id')->get();

        return $this->response(compact('settings', 'accounts', 'periods', 'rules', 'jobs', 'entries', 'bank', 'audit', 'balances') + [
            'invoices' => $invoiceRows, 'allocations' => DB::table('accounting_payment_allocations')->where('company_id', $id)->get(),
            'advances' => DB::table('accounting_advances')->where('company_id', $id)->get(),
            'estimates' => DB::table('accounting_cost_estimates')->where('company_id', $id)->get(),
            'deliveries' => DB::table('accounting_invoice_deliveries')->where('company_id', $id)->orderByDesc('id')->get(),
            'job_margins' => $this->jobMargins($id),
            'partners' => DB::table('accounting_partners')->where('company_id', $id)->orderBy('name')->get(),
        ]);
    }

    public function settings(Request $r): JsonResponse
    {
        $id = $this->company($r, 'setup');
        $data = $r->validate(['jurisdiction' => ['required', Rule::in(['FBiH', 'RS', 'BD'])], 'base_currency' => ['required', 'regex:/^[A-Z]{3}$/'], 'invoice_prefix' => ['required', 'regex:/^[A-Z0-9-]{1,40}$/']]);
        DB::transaction(function () use ($id, $data, $r) {
            Company::whereKey($id)->lockForUpdate()->first();
            $previous = DB::table('accounting_settings')->where('company_id', $id)->first();
            if ($previous && (DB::table('invoices')->where('company_id', $id)->where('accounting_managed', true)->exists()
                || DB::table('accounting_entries')->where('company_id', $id)->exists() || DB::table('accounting_bank_transactions')->where('company_id', $id)->exists())) {
                $this->ledger->require($previous->base_currency === $data['base_currency'] && $previous->jurisdiction === $data['jurisdiction'], 'Jurisdiction and base currency cannot change after accounting starts.');
            }
            DB::table('accounting_settings')->updateOrInsert(['company_id' => $id], $data + ['updated_at' => now(), 'created_at' => now()]);
            $this->ledger->audit($id, $r->user()->id, 'settings', $id, 'configured', $data);
        });

        return $this->response($data);
    }

    public function account(Request $r): JsonResponse
    {
        $id = $this->company($r, 'setup');
        $data = $r->validate(['code' => ['required', 'string', 'max:30'], 'name' => ['required', 'string', 'max:255'],
            'kind' => ['required', Rule::in(['asset', 'liability', 'equity', 'income', 'expense'])]]);

        return $this->response(DB::transaction(function () use ($r, $id, $data) {
            DB::table('accounting_settings')->where('company_id', $id)->lockForUpdate()->first();
            $this->ledger->require(! DB::table('accounting_accounts')->where('company_id', $id)->where('code', $data['code'])->exists(), 'Account code already exists; used accounts cannot be redefined.');
            $key = DB::table('accounting_accounts')->insertGetId($data + ['company_id' => $id, 'approved_by' => $r->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->ledger->audit($id, $r->user()->id, 'account', $key, 'account_confirmed', $data);

            return DB::table('accounting_accounts')->find($key);
        }), 201);
    }

    public function period(Request $r): JsonResponse
    {
        $id = $this->company($r, 'periods');
        $data = $r->validate(['name' => ['required', 'string', 'max:80'], 'starts_on' => ['required', 'date_format:Y-m-d'], 'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on']]);

        return $this->response(DB::transaction(function () use ($id, $data, $r) {
            DB::table('accounting_settings')->where('company_id', $id)->lockForUpdate()->first();
            $this->ledger->require(! DB::table('accounting_periods')->where('company_id', $id)->where('starts_on', '<=', $data['ends_on'])->where('ends_on', '>=', $data['starts_on'])->exists(), 'Periods cannot overlap.');
            $key = DB::table('accounting_periods')->insertGetId($data + ['company_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
            $this->ledger->audit($id, $r->user()->id, 'period', $key, 'created', $data);

            return DB::table('accounting_periods')->find($key);
        }), 201);
    }

    public function periodStatus(Request $r, int $company, int $period): JsonResponse
    {
        $id = $this->company($r, 'periods');
        $data = $r->validate(['status' => ['required', Rule::in(['open', 'locked'])], 'reason' => ['required', 'string', 'max:2000']]);
        DB::transaction(function () use ($id, $period, $data, $r) {
            DB::table('accounting_settings')->where('company_id', $id)->lockForUpdate()->first();
            $row = DB::table('accounting_periods')->where('company_id', $id)->where('id', $period)->lockForUpdate()->first();
            abort_unless($row, 404);
            DB::table('accounting_periods')->where('id', $period)->update(['status' => $data['status'], 'locked_by' => $data['status'] === 'locked' ? $r->user()->id : null,
                'locked_at' => $data['status'] === 'locked' ? now() : null, 'updated_at' => now()]);
            $this->ledger->audit($id, $r->user()->id, 'period', $period, $data['status'] === 'locked' ? 'locked' : 'reopened', $data);
        });

        return $this->response($data);
    }

    public function rule(Request $r): JsonResponse
    {
        $id = $this->company($r, 'rules');
        $data = $r->validate(['name' => ['required', 'string', 'max:255'], 'jurisdiction' => ['required', Rule::in(['BA', 'FBiH', 'RS', 'BD'])],
            'tax_type' => ['required', Rule::in(['vat', 'profit', 'income', 'contributions', 'fiscalization'])], 'treatment' => ['required', 'string', 'max:60'],
            'conditions' => ['required', 'string', 'max:10000'], 'rate' => ['nullable', 'numeric', 'min:0', 'max:100'], 'deductible_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'effective_from' => ['required', 'date_format:Y-m-d'], 'effective_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
            'applies_from' => ['required', 'date_format:Y-m-d'], 'source_url' => ['required', 'url', 'max:2000'], 'article' => ['required', 'string', 'max:120'], 'version' => ['required', 'string', 'max:120']]);
        $key = DB::table('accounting_tax_rules')->insertGetId($data + ['company_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
        $this->ledger->audit($id, $r->user()->id, 'rule', $key, 'unverified_rule_created', $data);

        return $this->response(DB::table('accounting_tax_rules')->find($key), 201);
    }

    public function approveRule(Request $r, int $company, int $rule): JsonResponse
    {
        $id = $this->company($r, 'rules');
        $data = $r->validate(['review_note' => ['required', 'string', 'max:2000']]);
        DB::transaction(function () use ($id, $rule, $r, $data) {
            DB::table('accounting_settings')->where('company_id', $id)->lockForUpdate()->first();
            $row = DB::table('accounting_tax_rules')->where('company_id', $id)->where('id', $rule)->lockForUpdate()->first();
            abort_unless($row, 404);
            $this->ledger->require(! $row->approved_by, 'Approved rule versions are immutable. Create a new version.');
            DB::table('accounting_tax_rules')->where('id', $rule)->update(['verification_status' => 'approved', 'approved_by' => $r->user()->id, 'approved_at' => now(), 'updated_at' => now()]);
            $this->ledger->audit($id, $r->user()->id, 'rule', $rule, 'professionally_approved', $data);
        });

        return $this->response(DB::table('accounting_tax_rules')->find($rule));
    }

    private function invoiceData(Request $r): array
    {
        return $r->validate(['revision' => ['sometimes', 'integer', 'min:1'], 'direction' => ['sometimes', Rule::in(['incoming', 'outgoing'])],
            'partner_id' => ['nullable', 'integer'], 'partner_name' => ['nullable', 'string', 'max:255'], 'partner_tax_number' => ['nullable', 'string', 'max:100'], 'supplier_number' => ['nullable', 'string', 'max:100'],
            'customer_user_id' => ['nullable', 'integer', 'exists:users,id'], 'issued_at' => ['nullable', 'date_format:Y-m-d'], 'due_at' => ['nullable', 'date_format:Y-m-d'],
            'event_date' => ['nullable', 'date_format:Y-m-d'], 'tax_date' => ['nullable', 'date_format:Y-m-d'], 'posting_date' => ['nullable', 'date_format:Y-m-d'],
            'currency' => ['sometimes', 'regex:/^[A-Z]{3}$/'], 'exchange_rate' => ['nullable', 'numeric', 'gt:0', 'max:1000000'],
            'exchange_date' => ['nullable', 'date_format:Y-m-d'], 'exchange_source' => ['nullable', 'string', 'max:255'], 'exchange_reason' => ['nullable', 'string', 'max:2000'],
            'items' => ['sometimes', 'array', 'max:300'], 'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'], 'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.account_id' => ['nullable', 'integer'], 'items.*.tax_rule_id' => ['nullable', 'integer'], 'items.*.workspace_id' => ['nullable', 'integer'],
            'items.*.allocations' => ['sometimes', 'array', 'min:1', 'max:100'], 'items.*.allocations.*.amount' => ['required', 'numeric', 'min:0'],
            'items.*.allocations.*.workspace_id' => ['nullable', 'integer'], 'items.*.allocations.*.estimate_id' => ['nullable', 'integer'],
            'document_ids' => ['sometimes', 'array', 'max:30'], 'document_ids.*' => ['integer']]);
    }

    public function createInvoice(Request $r): JsonResponse
    {
        return $this->response($this->invoices->save($this->company($r, 'prepare'), $r->user()->id, $this->invoiceData($r)), 201);
    }

    public function updateInvoice(Request $r, int $company, int $invoice): JsonResponse
    {
        return $this->response($this->invoices->save($this->company($r, 'prepare'), $r->user()->id, $this->invoiceData($r), $invoice));
    }

    public function invoiceAction(Request $r, int $company, int $invoice, string $action): JsonResponse
    {
        $ability = match ($action) {
            'approve', 'reject' => 'approve', 'post' => 'post', default => 'prepare'
        };
        $id = $this->company($r, $ability);
        $data = $r->validate(['control_account_id' => ['nullable', 'integer'], 'vat_account_id' => ['nullable', 'integer']]);

        return $this->response($this->invoices->transition($id, $r->user()->id, $invoice, $action, $data));
    }

    public function preview(Request $r, int $company, int $invoice): JsonResponse
    {
        $id = $this->company($r);
        $row = Invoice::where('company_id', $id)->where('accounting_managed', true)->findOrFail($invoice);
        $data = $r->validate(['control_account_id' => ['required', 'integer'], 'vat_account_id' => ['nullable', 'integer']]);
        $this->invoices->validateReady($row);

        return $this->response($this->invoices->postingLines($row, $data));
    }

    public function bank(Request $r): JsonResponse
    {
        $id = $this->company($r, 'payments');
        $data = $r->validate(['reference' => ['required', 'string', 'max:120'], 'bank_account' => ['required', 'string', 'max:100'],
            'partner_id' => ['required', 'integer'], 'partner_name' => ['nullable', 'string', 'max:255'], 'direction' => ['required', Rule::in(['incoming', 'outgoing'])],
            'transaction_date' => ['required', 'date_format:Y-m-d'], 'amount' => ['required', 'numeric', 'gt:0'], 'currency' => ['required', 'regex:/^[A-Z]{3}$/'],
            'exchange_rate' => ['required', 'numeric', 'gt:0', 'max:1000000'], 'exchange_source' => ['required', 'string', 'max:255']]);

        return $this->response(DB::transaction(function () use ($id, $data, $r) {
            $settings = DB::table('accounting_settings')->where('company_id', $id)->lockForUpdate()->first();
            $partner = DB::table('accounting_partners')->where('company_id', $id)->where('id', $data['partner_id'])->first();
            $this->ledger->require($partner !== null, 'Partner belongs to another company.');
            $data['partner_name'] = $partner->name;
            $this->ledger->require($settings && ($data['currency'] !== $settings->base_currency || bccomp((string) $data['exchange_rate'], '1', 8) === 0), 'Base currency exchange rate must equal one.');
            $this->ledger->require(! DB::table('accounting_bank_transactions')->where('company_id', $id)->where('bank_account', $data['bank_account'])->where('reference', $data['reference'])->exists(), 'Bank reference already imported.');
            $data['amount'] = Decimal::value($data['amount']);
            $data['exchange_rate'] = Decimal::value($data['exchange_rate'], 8);
            $key = DB::table('accounting_bank_transactions')->insertGetId($data + ['company_id' => $id, 'created_by' => $r->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->ledger->audit($id, $r->user()->id, 'bank', $key, 'recorded', $data);

            return DB::table('accounting_bank_transactions')->find($key);
        }), 201);
    }

    public function allocate(Request $r): JsonResponse
    {
        $id = $this->company($r, 'payments');
        $this->access->authorize($r->user(), $id, 'post');
        $data = $r->validate(['bank_transaction_id' => ['required', 'integer'], 'invoice_id' => ['required', 'integer'], 'amount' => ['required', 'numeric', 'gt:0'],
            'request_key' => ['required', 'string', 'max:100'], 'bank_account_id' => ['required', 'integer'], 'fx_gain_account_id' => ['nullable', 'integer'], 'fx_loss_account_id' => ['nullable', 'integer'], 'posting_date' => ['nullable', 'date_format:Y-m-d']]);

        return $this->response($this->payments->allocate($id, $r->user()->id, $data));
    }

    public function advance(Request $r): JsonResponse
    {
        $id = $this->company($r, 'payments');
        $this->access->authorize($r->user(), $id, 'post');
        $data = $r->validate(['bank_transaction_id' => ['required', 'integer'], 'bank_account_id' => ['required', 'integer'], 'control_account_id' => ['required', 'integer']]);

        return $this->response(DB::transaction(function () use ($id, $r, $data) {
            DB::table('accounting_settings')->where('company_id', $id)->lockForUpdate()->first();
            $bank = DB::table('accounting_bank_transactions')->where('company_id', $id)->where('id', $data['bank_transaction_id'])->first();
            abort_unless($bank, 404);
            $this->ledger->require(bccomp($this->payments->allocated('bank_transaction_id', $bank->id), '0', 2) === 0, 'Only an unallocated transaction can become an advance.');
            $existing = DB::table('accounting_advances')->where('bank_transaction_id', $bank->id)->first();
            if ($existing) {
                return $existing;
            }
            $key = DB::table('accounting_advances')->insertGetId(['company_id' => $id, 'bank_transaction_id' => $bank->id,
                'partner_name' => $bank->partner_name, 'amount' => $bank->amount, 'control_account_id' => $data['control_account_id'], 'created_by' => $r->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            $amount = Decimal::convert((string) $bank->amount, (string) $bank->exchange_rate);
            $outgoing = $bank->direction === 'outgoing';
            $this->ledger->post($id, $r->user()->id, ['event_key' => 'advance:'.$key, 'posting_date' => $bank->transaction_date,
                'description' => 'Advance / '.$bank->reference, 'lines' => [
                    ['account_id' => $data['control_account_id'], 'debit' => $outgoing ? $amount : '0', 'credit' => $outgoing ? '0' : $amount],
                    ['account_id' => $data['bank_account_id'], 'debit' => $outgoing ? '0' : $amount, 'credit' => $outgoing ? $amount : '0'],
                ]]);
            $this->ledger->audit($id, $r->user()->id, 'advance', $key, 'recorded');

            return DB::table('accounting_advances')->find($key);
        }), 201);
    }

    public function journal(Request $r): JsonResponse
    {
        $id = $this->company($r, 'post');
        $data = $r->validate(['event_key' => ['required', 'regex:/^(manual|opening):[A-Za-z0-9-]{1,100}$/'], 'posting_date' => ['required', 'date_format:Y-m-d'],
            'description' => ['required', 'string', 'max:2000'], 'lines' => ['required', 'array', 'min:2', 'max:300'],
            'lines.*.account_id' => ['required', 'integer'], 'lines.*.debit' => ['required', 'numeric', 'min:0'], 'lines.*.credit' => ['required', 'numeric', 'min:0']]);

        return $this->response($this->ledger->post($id, $r->user()->id, $data));
    }

    public function reverse(Request $r, int $company, int $entry): JsonResponse
    {
        $id = $this->company($r, 'correct');
        $data = $r->validate(['posting_date' => ['required', 'date_format:Y-m-d'], 'reason' => ['required', 'string', 'max:2000']]);

        return $this->response($this->ledger->reverse($id, $r->user()->id, $entry, $data['posting_date'], $data['reason']));
    }

    public function vat(Request $r): JsonResponse
    {
        $id = $this->company($r);
        $data = $r->validate(['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from']]);
        $rows = DB::table('invoice_items')->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->join('accounting_entries', 'accounting_entries.invoice_id', '=', 'invoices.id')
            ->join('accounting_tax_rules', 'accounting_tax_rules.id', '=', 'invoice_items.tax_rule_id')->where('invoices.company_id', $id)
            ->whereBetween('invoices.tax_date', [$data['from'], $data['to']])->get(['invoices.id as invoice_id', 'invoices.number', 'invoices.supplier_number', 'invoices.direction', 'invoices.tax_date',
                'invoices.currency', 'invoices.exchange_rate', 'invoice_items.total as net', 'invoice_items.tax_amount', 'invoice_items.tax_rate', 'invoice_items.deductible_percent',
                'invoices.corrects_invoice_id', 'accounting_tax_rules.id as rule_id', 'accounting_tax_rules.version', 'accounting_tax_rules.source_url', 'accounting_tax_rules.article']);
        foreach ($rows as $row) {
            $row->net_base = Decimal::convert((string) $row->net, (string) $row->exchange_rate);
            $row->vat_base = Decimal::convert((string) $row->tax_amount, (string) $row->exchange_rate);
            $row->deductible_base = $row->direction === 'incoming' ? Decimal::round(bcdiv(bcmul($row->vat_base, (string) $row->deductible_percent, 8), '100', 8)) : '0.00';
            if ($row->corrects_invoice_id) {
                foreach (['net_base', 'vat_base', 'deductible_base'] as $k) {
                    $row->{$k} = bcsub('0', $row->{$k}, 2);
                }
            }
        }

        return $this->response($rows);
    }

    public function corrective(Request $r, int $company, int $invoice): JsonResponse
    {
        $id = $this->company($r, 'correct');
        $data = $r->validate(['posting_date' => ['required', 'date_format:Y-m-d'], 'reason' => ['required', 'string', 'max:2000']]);

        return $this->response($this->invoices->corrective($id, $r->user()->id, $invoice, $data['posting_date'], $data['reason']), 201);
    }

    public function printInvoice(Request $r, int $company, int $invoice)
    {
        $id = $this->company($r);
        $doc = DB::table('accounting_issued_documents')->where('company_id', $id)->where('invoice_id', $invoice)->first();
        abort_unless($doc, 404);

        return response($doc->html)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    public function deliver(Request $r, int $company, int $invoice): JsonResponse
    {
        $id = $this->company($r, 'prepare');
        $data = $r->validate(['recipient' => ['required', 'email', 'max:255'], 'document_ids' => ['required', 'array', 'max:30'], 'document_ids.*' => ['integer'], 'request_key' => ['required', 'string', 'max:100']]);
        $delivery = DB::transaction(function () use ($id, $invoice, $r, $data) {
            DB::table('accounting_settings')->where('company_id', $id)->lockForUpdate()->first();
            $row = Invoice::where('company_id', $id)->where('accounting_managed', true)->findOrFail($invoice);
            $this->ledger->require($row->issuance_status === 'issued', 'Only an issued invoice can be sent.');
            $ids = DB::table('accounting_invoice_documents')->where('invoice_id', $invoice)->pluck('document_id')->all();
            $this->ledger->require(count(array_diff($data['document_ids'], $ids)) === 0, 'Attachments must be linked to this invoice.');
            $old = DB::table('accounting_invoice_deliveries')->where('company_id', $id)->where('request_key', $data['request_key'])->first();
            if ($old) {
                $this->ledger->require((int) $old->invoice_id === $invoice && $old->recipient === $data['recipient'], 'Delivery request key is already used.');

                return $old;
            }
            $key = DB::table('accounting_invoice_deliveries')->insertGetId(['company_id' => $id, 'invoice_id' => $invoice, 'recipient' => $data['recipient'],
                'document_ids' => json_encode($data['document_ids']), 'request_key' => $data['request_key'], 'requested_by' => $r->user()->id, 'created_at' => now(), 'updated_at' => now()]);

            return DB::table('accounting_invoice_deliveries')->find($key);
        });
        // Claim once before sending. Retried requests never send twice, including a pending delivery.
        if (DB::table('accounting_invoice_deliveries')->where('id', $delivery->id)->where('status', 'pending')->update(['status' => 'sending', 'updated_at' => now()])) {
            try {
                $doc = DB::table('accounting_issued_documents')->where('company_id', $id)->where('invoice_id', $invoice)->first();
                $this->ledger->require($doc !== null, 'Issued invoice document is missing.');
                $this->ledger->require(! in_array(config('mail.default'), ['log', 'array'], true), 'Configure a real mail transport before sending invoices.');
                Mail::html($doc->html, function ($message) use ($data, $invoice) {
                    $message->to($data['recipient'])->subject(Invoice::findOrFail($invoice)->number);
                    foreach ($data['document_ids'] as $documentId) {
                        $stored = DB::table('accounting_issued_documents')->where('document_id', $documentId)->first();
                        $file = Document::findOrFail($documentId);
                        if ($stored) {
                            $message->attachData($stored->html, $file->name, ['mime' => 'text/html']);
                        } else {
                            $this->ledger->require(Storage::disk('local')->exists('documents/'.$file->path), 'Attachment file is missing.');
                            $message->attachData(Storage::disk('local')->get('documents/'.$file->path), $file->name, ['mime' => $file->mime_type]);
                        }
                    }
                });
                DB::table('accounting_invoice_deliveries')->where('id', $delivery->id)->update(['status' => 'sent', 'sent_at' => now(), 'updated_at' => now()]);
                $this->ledger->audit($id, $r->user()->id, 'invoice', $invoice, 'sent', ['delivery_id' => $delivery->id, 'recipient' => $data['recipient']]);
            } catch (\Throwable $e) {
                DB::table('accounting_invoice_deliveries')->where('id', $delivery->id)->update(['status' => 'failed', 'error' => 'Delivery failed. Check mail configuration and attachments.', 'updated_at' => now()]);
                $this->ledger->audit($id, $r->user()->id, 'invoice', $invoice, 'delivery_failed', ['delivery_id' => $delivery->id]);
                report($e);
            }
        }

        return $this->response(DB::table('accounting_invoice_deliveries')->find($delivery->id));
    }

    public function importAccounts(Request $r): JsonResponse
    {
        $id = $this->company($r, 'setup');
        $data = $r->validate(['accounts' => ['required', 'array', 'min:1', 'max:1000'], 'accounts.*.code' => ['required', 'string', 'max:30', 'distinct'],
            'accounts.*.name' => ['required', 'string', 'max:255'], 'accounts.*.kind' => ['required', Rule::in(['asset', 'liability', 'equity', 'income', 'expense'])],
            'review_note' => ['required', 'string', 'max:2000']]);
        DB::transaction(function () use ($id, $data, $r) {
            DB::table('accounting_settings')->where('company_id', $id)->lockForUpdate()->first();
            foreach ($data['accounts'] as $account) {
                $this->ledger->require(! DB::table('accounting_accounts')->where('company_id', $id)->where('code', $account['code'])->exists(), 'Import would redefine an existing account.');
                DB::table('accounting_accounts')->insert($account + ['company_id' => $id, 'approved_by' => $r->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->ledger->audit($id, $r->user()->id, 'accounts', $id, 'reviewed_import', ['count' => count($data['accounts']), 'review_note' => $data['review_note']]);
        });

        return $this->response(['imported' => count($data['accounts'])]);
    }

    public function estimate(Request $r): JsonResponse
    {
        $id = $this->company($r, 'prepare');
        $data = $r->validate(['workspace_id' => ['required', 'integer'], 'description' => ['required', 'string', 'max:255'], 'amount' => ['required', 'numeric', 'min:0'], 'currency' => ['required', 'regex:/^[A-Z]{3}$/']]);
        $this->invoices->workspace($id, $data['workspace_id'], 'incoming');
        $data['amount'] = Decimal::value($data['amount']);
        $key = DB::table('accounting_cost_estimates')->insertGetId($data + ['company_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
        $this->ledger->audit($id, $r->user()->id, 'estimate', $key, 'created', $data);

        return $this->response(DB::table('accounting_cost_estimates')->find($key), 201);
    }

    private function jobMargins(int $id): array
    {
        $result = [];
        foreach (DB::table('shipment_workspace')->where('provider_company_id', $id)->get() as $job) {
            if (! isset($job->agreed_amount, $job->currency)) {
                continue;
            }
            $actual = DB::table('accounting_cost_allocations')->join('invoice_items', 'invoice_items.id', '=', 'accounting_cost_allocations.invoice_item_id')
                ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')->where('accounting_cost_allocations.company_id', $id)
                ->where('accounting_cost_allocations.workspace_id', $job->id)->where('invoices.approval_status', 'approved')
                ->whereNull('invoices.corrects_invoice_id')->whereNotExists(function ($q) {
                    $q->selectRaw('1')->from('accounting_entries as original')->join('accounting_entries as correction', 'correction.reverses_entry_id', '=', 'original.id')
                        ->whereColumn('original.invoice_id', 'invoices.id');
                })->get(['accounting_cost_allocations.*', 'invoices.currency']);
            $estimates = DB::table('accounting_cost_estimates')->where('company_id', $id)->where('workspace_id', $job->id)->get();
            $unreplaced = $estimates->filter(fn ($e) => ! $actual->contains('estimate_id', $e->id));
            $differentCurrency = $actual->contains(fn ($a) => $a->currency !== $job->currency) || $unreplaced->contains(fn ($a) => $a->currency !== $job->currency);
            $cost = $differentCurrency ? null : bcadd(Decimal::sum($actual->pluck('amount')), Decimal::sum($unreplaced->pluck('amount')), 2);
            $result[] = ['workspace_id' => $job->id, 'currency' => $job->currency, 'revenue' => $job->agreed_amount, 'cost' => $cost,
                'margin' => $cost === null ? null : bcsub((string) $job->agreed_amount, $cost, 2), 'requires_currency_review' => $differentCurrency];
        }

        return $result;
    }

    public function reports(Request $r): JsonResponse
    {
        $id = $this->company($r);
        $range = $r->validate(['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from']]);
        $lines = DB::table('accounting_entry_lines')->join('accounting_entries', 'accounting_entries.id', '=', 'accounting_entry_lines.entry_id')
            ->where('accounting_entries.company_id', $id)->where('posting_date', '<=', $range['to'])->get(['accounting_entry_lines.*', 'accounting_entries.posting_date']);
        $trial = [];
        $categories = array_fill_keys(['asset', 'liability', 'equity', 'income', 'expense'], '0.00');
        foreach (DB::table('accounting_accounts')->where('company_id', $id)->orderBy('code')->get() as $account) {
            $own = $lines->where('account_id', $account->id);
            $before = $own->filter(fn ($l) => $l->posting_date < $range['from']);
            $within = $own->filter(fn ($l) => $l->posting_date >= $range['from']);
            $opening = bcsub(Decimal::sum($before->pluck('debit')), Decimal::sum($before->pluck('credit')), 2);
            $debit = Decimal::sum($within->pluck('debit'));
            $credit = Decimal::sum($within->pluck('credit'));
            $closing = bcadd($opening, bcsub($debit, $credit, 2), 2);
            $trial[] = ['account_id' => $account->id, 'code' => $account->code, 'name' => $account->name, 'kind' => $account->kind,
                'opening_balance' => $opening, 'debit' => $debit, 'credit' => $credit, 'closing_balance' => $closing];
            $signed = in_array($account->kind, ['liability', 'equity', 'income'], true) ? bcsub('0', $closing, 2) : $closing;
            if (in_array($account->kind, ['income', 'expense'], true)) {
                $signed = $account->kind === 'income' ? bcsub($credit, $debit, 2) : bcsub($debit, $credit, 2);
            }
            $categories[$account->kind] = bcadd($categories[$account->kind], $signed, 2);
        }

        return $this->response(['trial_balance' => $trial, 'categories' => $categories, 'result' => bcsub($categories['income'], $categories['expense'], 2),
            'currency' => DB::table('accounting_settings')->where('company_id', $id)->value('base_currency'),
            'debit' => Decimal::sum(array_column($trial, 'debit')), 'credit' => Decimal::sum(array_column($trial, 'credit'))]);
    }

    public function partner(Request $r): JsonResponse
    {
        $id = $this->company($r, 'prepare');
        $data = $r->validate(['name' => ['required', 'string', 'max:255'], 'tax_number' => ['nullable', 'string', 'max:100'], 'vat_number' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:255'], 'country_code' => ['required', 'regex:/^[A-Z]{2}$/'], 'email' => ['nullable', 'email', 'max:255']]);

        return $this->response(DB::transaction(function () use ($r, $id, $data) {
            DB::table('accounting_settings')->where('company_id', $id)->lockForUpdate()->first();
            if (! empty($data['tax_number'])) {
                $this->ledger->require(! DB::table('accounting_partners')->where('company_id', $id)->where('tax_number', $data['tax_number'])->exists(), 'Partner tax ID already exists.');
            }
            $key = DB::table('accounting_partners')->insertGetId($data + ['company_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
            $this->ledger->audit($id, $r->user()->id, 'partner', $key, 'created', $data);

            return DB::table('accounting_partners')->find($key);
        }), 201);
    }

    public function fromJob(Request $r): JsonResponse
    {
        $id = $this->company($r, 'prepare');
        $input = $r->validate(['workspace_id' => ['required', 'integer']]);

        return $this->response(DB::transaction(function () use ($id, $r, $input) {
            DB::table('accounting_settings')->where('company_id', $id)->lockForUpdate()->first();
            $job = $this->invoices->workspace($id, $input['workspace_id'], 'outgoing');
            $this->ledger->require($job->status !== 'cancelled', 'Cancelled jobs cannot be invoiced.');
            $customer = Customer::where('user_id', $job->customer_user_id)->first();
            $user = User::findOrFail($job->customer_user_id);
            $partner = $customer ? DB::table('accounting_partners')->where('company_id', $id)->where('customer_id', $customer->id)->first() : null;
            if (! $partner) {
                $partnerId = DB::table('accounting_partners')->insertGetId(['company_id' => $id, 'customer_id' => $customer?->id,
                    'name' => $customer?->name ?: $user->name, 'email' => $user->email, 'country_code' => $user->country_code ?: 'BA',
                    'created_at' => now(), 'updated_at' => now()]);
            } else {
                $partnerId = $partner->id;
            }
            $snapshot = json_decode($job->load_snapshot ?? '{}', true) ?: [];

            return $this->invoices->save($id, $r->user()->id, ['direction' => 'outgoing', 'partner_id' => $partnerId, 'customer_user_id' => $job->customer_user_id,
                'currency' => $job->currency, 'items' => [['description' => $snapshot['title'] ?? $job->reference, 'quantity' => '1',
                    'unit_price' => (string) $job->agreed_amount, 'workspace_id' => $job->id]]]);
        }), 201);
    }

    public function attachDocuments(Request $r, int $company, int $invoice): JsonResponse
    {
        $id = $this->company($r, 'prepare');
        $data = $r->validate(['document_ids' => ['required', 'array', 'min:1', 'max:30'], 'document_ids.*' => ['integer']]);
        DB::transaction(function () use ($id, $invoice, $r, $data): void {
            DB::table('accounting_settings')->where('company_id', $id)->lockForUpdate()->first();
            Invoice::where('company_id', $id)->where('accounting_managed', true)->findOrFail($invoice);
            foreach (array_unique($data['document_ids']) as $documentId) {
                $file = DB::table('documents')->where('id', $documentId)->first();
                $owner = (int) DB::table('companies')->where('id', $id)->value('owner_user_id');
                $this->ledger->require($file && ((int) $file->uploaded_by_user_id === $owner || DB::table('company_user')
                    ->where('company_id', $id)->where('user_id', $file->uploaded_by_user_id)->where('status', 'active')->exists()), 'Document belongs to another company.');
                DB::table('accounting_invoice_documents')->insertOrIgnore(['invoice_id' => $invoice, 'document_id' => $documentId]);
            }
            $this->ledger->audit($id, $r->user()->id, 'invoice', $invoice, 'supporting_documents_attached', $data);
        });

        return $this->response($data);
    }
}
