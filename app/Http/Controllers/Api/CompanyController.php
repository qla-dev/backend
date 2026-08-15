<?php

namespace App\Http\Controllers\Api;

use App\Models\Company;

class CompanyController extends CrudController
{
    protected function modelClass(): string
    {
        return Company::class;
    }

    protected function rules(bool $updating = false): array
    {
        $p = $updating ? 'sometimes' : 'required';

        return ['owner_user_id' => [$p, 'integer', 'exists:users,id'], 'name' => [$p, 'string', 'max:255'], 'slug' => [$p, 'string', 'max:255'], 'email' => ['nullable', 'email'], 'phone' => ['nullable', 'string', 'max:50'], 'tax_number' => ['nullable', 'string', 'max:100'], 'registration_number' => ['nullable', 'string', 'max:100'], 'country_code' => [$p, 'string', 'size:2'], 'city' => ['nullable', 'string', 'max:120'], 'address' => ['nullable', 'string', 'max:255'], 'plan' => ['sometimes', 'string', 'max:50'], 'status' => ['sometimes', 'string', 'max:50'], 'verified_at' => ['nullable', 'date']];
    }

    protected function relations(): array
    {
        return ['owner', 'users', 'vehicles'];
    }

    protected function searchColumns(): array
    {
        return ['name', 'email', 'tax_number', 'registration_number'];
    }
}
