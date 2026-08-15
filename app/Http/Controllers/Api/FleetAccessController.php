<?php

namespace App\Http\Controllers\Api;

use App\Models\FleetAccess;

class FleetAccessController extends CrudController
{
    protected function modelClass(): string
    {
        return FleetAccess::class;
    }

    protected function relations(): array
    {
        return ['vehicle', 'user.role', 'grantedBy'];
    }

    protected function rules(bool $u = false): array
    {
        $p = $u ? 'sometimes' : 'required';

        return ['vehicle_id' => [$p, 'integer', 'exists:vehicles,id'], 'user_id' => [$p, 'integer', 'exists:users,id'], 'granted_by_user_id' => ['nullable', 'integer', 'exists:users,id'], 'can_view' => ['sometimes', 'boolean'], 'can_dispatch' => ['sometimes', 'boolean'], 'can_edit' => ['sometimes', 'boolean']];
    }
}
