<?php

namespace App\Http\Controllers\Api;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Services\Accounting\AccountingAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class InvoiceItemController extends CrudController
{
    protected function configureQuery(Builder $query): void
    {
        $query->whereHas('invoice.company', fn ($c) => $c->where('owner_user_id', auth()->id())
            ->orWhereHas('users', fn ($u) => $u->where('users.id', auth()->id())->where('company_user.status', 'active')));
        if (Schema::hasTable('accounting_permissions')) {
            $readable = DB::table('accounting_permissions')->where('user_id', auth()->id())->get()
                ->filter(fn ($p) => in_array('view', json_decode($p->abilities, true) ?: [], true))->pluck('company_id');
            $query->whereHas('invoice', fn ($q) => $q->where('accounting_managed', false)->orWhereIn('company_id', $readable));
        }
    }

    private function writable(Request $r, int $invoiceId): void
    {
        $invoice = Invoice::findOrFail($invoiceId);
        app(AccountingAccess::class)->member($r->user(), (int) $invoice->company_id);
        abort_if($invoice->accounting_managed, 409, 'Use Accounting to change invoice lines.');
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate(['invoice_id' => ['required', 'integer']]);
        $this->writable($request, $request->integer('invoice_id'));

        return parent::store($request);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $row = InvoiceItem::findOrFail($id);
        $this->writable($request, $row->invoice_id);
        if ($request->filled('invoice_id')) {
            $this->writable($request, $request->integer('invoice_id'));
        }

        return parent::update($request, $id);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $row = InvoiceItem::findOrFail($id);
        $this->writable($request, $row->invoice_id);

        return parent::destroy($request, $id);
    }

    protected function modelClass(): string
    {
        return InvoiceItem::class;
    }

    protected function relations(): array
    {
        return ['invoice', 'freightLoad'];
    }

    protected function rules(bool $u = false): array
    {
        $p = $u ? 'sometimes' : 'required';

        return ['invoice_id' => [$p, 'integer', 'exists:invoices,id'], 'load_id' => ['nullable', 'integer', 'exists:loads,id'], 'description' => [$p, 'string', 'max:255'], 'quantity' => ['sometimes', 'numeric', 'min:0'], 'unit_price' => [$p, 'numeric', 'min:0'], 'total' => [$p, 'numeric', 'min:0']];
    }
}
