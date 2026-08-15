<?php

namespace App\Http\Controllers\Api;

use App\Models\Offer;

class OfferController extends CrudController
{
    protected function modelClass(): string
    {
        return Offer::class;
    }

    protected function relations(): array
    {
        return ['freightLoad.stops', 'company', 'driver', 'creator'];
    }

    protected function rules(bool $u = false): array
    {
        $p = $u ? 'sometimes' : 'required';

        return ['load_id' => [$p, 'integer', 'exists:loads,id'], 'company_id' => ['nullable', 'integer', 'exists:companies,id'], 'driver_user_id' => ['nullable', 'integer', 'exists:users,id'], 'created_by_user_id' => [$p, 'integer', 'exists:users,id'], 'amount' => [$p, 'numeric', 'min:0'], 'currency' => ['sometimes', 'string', 'size:3'], 'status' => ['sometimes', 'string', 'max:50'], 'valid_until' => ['nullable', 'date'], 'message' => ['nullable', 'string']];
    }
}
