<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EntityResource;
use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate(['login' => ['required', 'string'], 'password' => ['required', 'string']]);
        $user = User::query()->with(['role', 'companies', 'customerProfile'])->where('email', $credentials['login'])->orWhere('username', $credentials['login'])->first();

        if (! $user || ! $user->is_active || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages(['login' => ['The provided credentials are incorrect.']]);
        }

        $user->forceFill(['last_login_at' => now()])->save();
        $token = $user->createToken('smartfreight-web')->plainTextToken;

        return response()->json(['message' => 'Login successful.', 'data' => ['token' => $token, 'token_type' => 'Bearer', 'user' => (new EntityResource($user))->resolve($request)], 'meta' => [], 'errors' => []]);
    }

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'role' => ['required', 'in:user,driver,company,finance'], 'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'], 'username' => ['required', 'string', 'max:80', 'unique:users,username'],
            'password' => ['required', 'string', 'min:8'], 'language' => ['nullable', 'string', 'max:5'],
        ]);
        $roleName = $data['role'];
        $role = Role::query()->where('name', $roleName)->firstOrFail();
        unset($data['role']);
        $user = DB::transaction(function () use ($data, $role, $roleName): User {
            $user = User::query()->create([...$data, 'role_id' => $role->id]);
            if ($roleName === 'user') {
                Customer::query()->create(['user_id' => $user->id, 'customer_type' => 'private', 'status' => 'active']);
            }

            return $user->load(['role', 'customerProfile']);
        });

        return response()->json(['message' => 'Registration successful.', 'data' => ['token' => $user->createToken('smartfreight-web')->plainTextToken, 'token_type' => 'Bearer', 'user' => (new EntityResource($user))->resolve($request)], 'meta' => [], 'errors' => []], 201);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load(['role', 'companies', 'driverProfile', 'customerProfile']);

        return response()->json(['message' => 'Authenticated user retrieved.', 'data' => (new EntityResource($user))->resolve($request), 'meta' => [], 'errors' => []]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:50'], 'language' => ['nullable', 'string', 'max:5'],
            'country_code' => ['nullable', 'string', 'size:2'], 'avatar_url' => ['nullable', 'url'],
            'password' => ['sometimes', 'string', 'min:8'],
        ]);
        if (isset($data['country_code'])) $data['country_code'] = strtoupper($data['country_code']);
        $user->update($data);

        return response()->json(['message' => 'Profile updated.', 'data' => (new EntityResource($user->load(['role', 'companies', 'driverProfile'])))->resolve($request), 'meta' => [], 'errors' => []]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logout successful.', 'data' => null, 'meta' => [], 'errors' => []]);
    }
}
