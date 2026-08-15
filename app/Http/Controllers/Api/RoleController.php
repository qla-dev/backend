<?php

namespace App\Http\Controllers\Api;

use App\Models\Role;

class RoleController extends CrudController
{
    protected function modelClass(): string
    {
        return Role::class;
    }

    protected function rules(bool $updating = false): array
    {
        $p = $updating ? 'sometimes' : 'required';

        return ['name' => [$p, 'string', 'max:80'], 'label' => [$p, 'string', 'max:120'], 'permissions' => ['nullable', 'array'], 'is_active' => ['sometimes', 'boolean']];
    }

    protected function relations(): array
    {
        return ['users'];
    }

    protected function searchColumns(): array
    {
        return ['name', 'label'];
    }
}
