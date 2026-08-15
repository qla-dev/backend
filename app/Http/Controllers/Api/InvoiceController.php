<?php

namespace App\Http\Controllers\Api;

use App\Models\Invoice;

class InvoiceController extends CrudController
{
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
