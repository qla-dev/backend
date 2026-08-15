<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\EntityResource;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    public function storeCustomer(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'username' => ['required', 'string', 'max:80', 'unique:users,username'], 'password' => ['required', 'string', 'min:8'],
            'phone' => ['nullable', 'string', 'max:50'], 'language' => ['nullable', 'string', 'max:5'],
            'country_code' => ['nullable', 'string', 'size:2'],
        ]);
        $data['role_id'] = Role::query()->where('name', 'user')->firstOrFail()->id;
        $data['language'] = $data['language'] ?? 'bs';
        $data['country_code'] = isset($data['country_code']) ? strtoupper($data['country_code']) : null;
        $data['is_active'] = true;
        $data['email_verified_at'] = now();
        $user = User::query()->create($data)->load($this->relations());

        return $this->success((new EntityResource($user))->resolve($request), 'Customer account created.', status: 201);
    }
}
