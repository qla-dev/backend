<?php

namespace App\Http\Controllers\Api;

use App\Models\DriverProfile;

class DriverProfileController extends CrudController
{
    protected function modelClass(): string
    {
        return DriverProfile::class;
    }

    protected function rules(bool $updating = false): array
    {
        $p = $updating ? 'sometimes' : 'required';

        return ['user_id' => [$p, 'integer', 'exists:users,id'], 'primary_company_id' => ['nullable', 'integer', 'exists:companies,id'], 'license_number' => [$p, 'string', 'max:120'], 'license_country_code' => [$p, 'string', 'size:2'], 'license_expires_at' => [$p, 'date'], 'availability_status' => ['sometimes', 'string', 'max:50'], 'rating' => ['sometimes', 'numeric', 'between:0,5'], 'completed_trips' => ['sometimes', 'integer', 'min:0'], 'certifications' => ['nullable', 'array']];
    }

    protected function relations(): array
    {
        return ['user.role', 'primaryCompany'];
    }

    protected function searchColumns(): array
    {
        return ['license_number', 'availability_status'];
    }
}
