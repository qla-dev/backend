<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EntityResource;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate(['login' => ['required', 'string'], 'password' => ['required', 'string']]);
        $user = User::query()->with(['role', 'companies'])->where('email', $credentials['login'])->orWhere('username', $credentials['login'])->first();

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
        $role = Role::query()->where('name', $data['role'])->firstOrFail();
        unset($data['role']);
        $user = User::query()->create([...$data, 'role_id' => $role->id]);
        $user->load('role');

        return response()->json(['message' => 'Registration successful.', 'data' => ['token' => $user->createToken('smartfreight-web')->plainTextToken, 'token_type' => 'Bearer', 'user' => (new EntityResource($user))->resolve($request)], 'meta' => [], 'errors' => []], 201);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load(['role', 'companies', 'driverProfile']);

        return response()->json(['message' => 'Authenticated user retrieved.', 'data' => (new EntityResource($user))->resolve($request), 'meta' => [], 'errors' => []]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logout successful.', 'data' => null, 'meta' => [], 'errors' => []]);
    }
}
