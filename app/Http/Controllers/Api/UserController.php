<?php

namespace App\Http\Controllers\Api;

use App\Models\User;

class UserController extends CrudController
{
    protected function modelClass(): string
    {
        return User::class;
    }

    protected function rules(bool $updating = false): array
    {
        $p = $updating ? 'sometimes' : 'required';

        return ['role_id' => [$p, 'integer', 'exists:roles,id'], 'name' => [$p, 'string', 'max:255'], 'email' => [$p, 'email', 'max:255'], 'username' => [$p, 'string', 'max:80'], 'password' => [$p, 'string', 'min:8'], 'phone' => ['nullable', 'string', 'max:50'], 'language' => ['nullable', 'string', 'max:5'], 'country_code' => ['nullable', 'string', 'size:2'], 'avatar_url' => ['nullable', 'url'], 'is_active' => ['sometimes', 'boolean']];
    }

    protected function relations(): array
    {
        return ['role', 'companies', 'driverProfile'];
    }

    protected function searchColumns(): array
    {
        return ['name', 'email', 'username'];
    }
}
