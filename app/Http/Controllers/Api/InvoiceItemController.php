<?php

namespace App\Http\Controllers\Api;

use App\Models\InvoiceItem;

class InvoiceItemController extends CrudController
{
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
