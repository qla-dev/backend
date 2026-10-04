<?php

namespace App\Http\Controllers\Api;

use App\Models\Invoice;
use App\Services\Accounting\AccountingAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class InvoiceController extends CrudController
{
    protected function configureQuery(Builder $query): void
    {
        $query->whereHas('company', function ($company): void {
            $company->where('owner_user_id', auth()->id())->orWhereHas('users', fn ($u) => $u->where('users.id', auth()->id())->where('company_user.status', 'active'));
        });
        if (Schema::hasTable('accounting_permissions')) {
            $readable = DB::table('accounting_permissions')->where('user_id', auth()->id())->get()
                ->filter(fn ($p) => in_array('view', json_decode($p->abilities, true) ?: [], true))->pluck('company_id');
            $query->where(fn ($q) => $q->where('accounting_managed', false)->orWhereIn('company_id', $readable));
        }
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $query = Invoice::query();
        $this->configureQuery($query);
        $invoice = $query->findOrFail($id);
        abort_if($invoice->accounting_managed, 409, 'Use Accounting to change this invoice.');
        $data = $request->validate($this->rules(true));
        if (isset($data['company_id'])) {
            app(AccountingAccess::class)->member($request->user(), (int) $data['company_id']);
        }

        return parent::update($request, $id);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $query = Invoice::query();
        $this->configureQuery($query);
        $invoice = $query->findOrFail($id);
        abort_if($invoice->accounting_managed, 409, 'Accounting invoices cannot be deleted through Finance.');

        return parent::destroy($request, $id);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate(['company_id' => ['required', 'integer']]);
        app(AccountingAccess::class)->member($request->user(), $request->integer('company_id'));

        return parent::store($request);
    }

    protected function modelClass(): string
    {
        return Invoice::class;
    }

    protected function relations(): array
    {
        return ['customer', 'company', 'freightLoad', 'issuer', 'items.freightLoad'];
    }

    protected function searchColumns(): array
    {
        return ['number', 'status'];
    }

    protected function rules(bool $u = false): array
    {
        $p = $u ? 'sometimes' : 'required';

        return ['customer_user_id' => [$p, 'integer', 'exists:users,id'], 'company_id' => ['nullable', 'integer', 'exists:companies,id'], 'load_id' => ['nullable', 'integer', 'exists:loads,id'], 'issued_by_user_id' => [$p, 'integer', 'exists:users,id'], 'number' => [$p, 'string', 'max:100'], 'status' => ['sometimes', 'string', 'max:50'], 'currency' => ['sometimes', 'string', 'size:3'], 'subtotal' => ['sometimes', 'numeric', 'min:0'], 'tax' => ['sometimes', 'numeric', 'min:0'], 'total' => ['sometimes', 'numeric', 'min:0'], 'issued_at' => [$p, 'date'], 'due_at' => [$p, 'date'], 'paid_at' => ['nullable', 'date']];
    }
}
