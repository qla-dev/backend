<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EntityResource;
use App\Models\Customer;
use App\Models\Driver;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate(['login' => ['required', 'string'], 'password' => ['required', 'string']]);
        $user = User::query()->with(['role', 'companies', 'customerProfile', 'driver'])->where('email', $credentials['login'])->orWhere('username', $credentials['login'])->first();

        if (! $user || ! $user->is_active || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages(['login' => ['The provided credentials are incorrect.']]);
        }

        if ($user->role?->name === 'user' && ! $user->customerProfile?->profile_authorized_at) {
            throw ValidationException::withMessages(['login' => ['The provided credentials are incorrect.']]);
        }

        if ($user->role?->name === 'driver' && ! $user->driver?->profile_authorized_at) {
            throw ValidationException::withMessages(['login' => ['The provided credentials are incorrect.']]);
        }

        $user->forceFill(['last_login_at' => now()])->save();
        $token = $user->createToken('freightbook-web')->plainTextToken;

        return response()->json(['message' => 'Login successful.', 'data' => ['token' => $token, 'token_type' => 'Bearer', 'user' => (new EntityResource($user))->resolve($request)], 'meta' => [], 'errors' => []]);
    }

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'role' => ['required', 'in:user,driver,company,finance'], 'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'], 'username' => ['required', 'string', 'max:80', 'unique:users,username'],
            'password' => ['required', 'string', 'min:8'], 'language' => ['nullable', 'string', 'max:5'],
            'license_number' => ['nullable', 'required_if:role,driver', 'string', 'max:120', 'unique:drivers,license_number'],
            'license_country_code' => ['nullable', 'required_if:role,driver', 'string', 'size:2'],
            'license_expires_at' => ['nullable', 'required_if:role,driver', 'date'],
        ]);
        $roleName = $data['role'];
        $role = Role::query()->where('name', $roleName)->firstOrFail();
        $driverData = [
            'license_number' => $data['license_number'] ?? null,
            'license_country_code' => $data['license_country_code'] ?? null,
            'license_expires_at' => $data['license_expires_at'] ?? null,
        ];
        unset($data['role'], $data['license_number'], $data['license_country_code'], $data['license_expires_at']);
        $user = DB::transaction(function () use ($data, $driverData, $role, $roleName): User {
            $user = User::query()->create([...$data, 'role_id' => $role->id]);
            if ($roleName === 'user') {
                Customer::query()->create([
                    'user_id' => $user->id,
                    'customer_type' => 'private',
                    'status' => 'active',
                    'profile_authorized_at' => now(),
                ]);
            }
            if ($roleName === 'driver') {
                Driver::query()->create([
                    'user_id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'profile_authorized_at' => now(),
                    'license_number' => $driverData['license_number'],
                    'license_country_code' => strtoupper((string) $driverData['license_country_code']),
                    'license_expires_at' => $driverData['license_expires_at'],
                    'availability_status' => 'available',
                ]);
            }

            return $user->load(['role', 'customerProfile', 'driver']);
        });

        return response()->json(['message' => 'Registration successful.', 'data' => ['token' => $user->createToken('freightbook-web')->plainTextToken, 'token_type' => 'Bearer', 'user' => (new EntityResource($user))->resolve($request)], 'meta' => [], 'errors' => []], 201);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load(['role', 'companies', 'driver', 'customerProfile']);

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
            'role_id' => ['sometimes', 'integer', Rule::exists('roles', 'id')->where(fn ($query) => $query->where('name', '!=', 'superadmin'))],
        ]);
        if (isset($data['country_code'])) {
            $data['country_code'] = strtoupper($data['country_code']);
        }
        $user->update($data);

        return response()->json(['message' => 'Profile updated.', 'data' => (new EntityResource($user->load(['role', 'companies', 'driver', 'customerProfile'])))->resolve($request), 'meta' => [], 'errors' => []]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logout successful.', 'data' => null, 'meta' => [], 'errors' => []]);
    }

    public function google(Request $request): JsonResponse
    {
        $token = $request->validate(['id_token' => ['required', 'string']])['id_token'];
        $account = $this->verifyGoogleIdToken($token);

        return $this->authenticateSocial($request, 'google_id', $account['sub'] ?? null, $account['email'] ?? null);
    }

    public function apple(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'identity_token' => ['required', 'string'],
        ]);
        $account = $this->verifyAppleIdentityToken($validated['identity_token']);

        return $this->authenticateSocial($request, 'apple_id', $account['sub'] ?? null, $account['email'] ?? null);
    }

    private function authenticateSocial(Request $request, string $providerField, mixed $providerId, mixed $email): JsonResponse
    {
        if (! is_string($providerId) || $providerId === '') {
            throw ValidationException::withMessages(['token' => ['The sign-in provider did not return a valid account ID.']]);
        }

        $query = User::query()->with(['role', 'companies', 'customerProfile', 'driver'])->where($providerField, $providerId);
        if (is_string($email) && $email !== '') {
            $query->orWhere('email', $email);
        }
        $user = $query->first();

        if (! $user) {
            throw ValidationException::withMessages([
                'token' => ['No Freightbook.ai account is linked to this account yet. Please register first, then connect it from your profile.'],
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages(['token' => ['This account is not active.']]);
        }

        if ($user->{$providerField} !== $providerId) {
            $user->forceFill([$providerField => $providerId])->save();
        }
        $user->forceFill(['last_login_at' => now()])->save();

        $token = $user->createToken('freightbook-mobile')->plainTextToken;

        return response()->json(['message' => 'Login successful.', 'data' => ['token' => $token, 'token_type' => 'Bearer', 'user' => (new EntityResource($user))->resolve($request)], 'meta' => [], 'errors' => []]);
    }

    private function verifyGoogleIdToken(string $idToken): array
    {
        $clientIds = config('services.google.client_ids', []);
        if ($clientIds === []) {
            throw ValidationException::withMessages(['id_token' => ['Google sign-in is not configured.']]);
        }

        $response = Http::get('https://oauth2.googleapis.com/tokeninfo', ['id_token' => $idToken]);
        $payload = $response->json();

        if (! $response->ok()
            || ! in_array($payload['aud'] ?? null, $clientIds, true)
            || ! in_array($payload['email_verified'] ?? null, [true, 'true'], true)
            || ($payload['exp'] ?? 0) < time()) {
            throw ValidationException::withMessages(['id_token' => ['Invalid or expired Google token.']]);
        }

        return $payload;
    }

    private function verifyAppleIdentityToken(string $identityToken): array
    {
        $clientIds = config('services.apple.client_ids', []);
        $parts = explode('.', $identityToken);
        if ($clientIds === [] || count($parts) !== 3) {
            throw ValidationException::withMessages(['identity_token' => ['Apple sign-in is not configured or the token is invalid.']]);
        }

        $header = json_decode($this->base64UrlDecode($parts[0]), true);
        $payload = json_decode($this->base64UrlDecode($parts[1]), true);
        $signature = $this->base64UrlDecode($parts[2]);

        if (! is_array($header) || ! is_array($payload) || ($header['alg'] ?? null) !== 'RS256') {
            throw ValidationException::withMessages(['identity_token' => ['Invalid Apple token.']]);
        }

        $keys = Http::get('https://appleid.apple.com/auth/keys');
        $key = $keys->ok()
            ? collect($keys->json('keys', []))->first(
                fn (array $candidate) => ($candidate['kid'] ?? null) === ($header['kid'] ?? null)
            )
            : null;

        $valid = $key
            && $this->verifyJwtSignature("{$parts[0]}.{$parts[1]}", $signature, $key)
            && ($payload['iss'] ?? null) === 'https://appleid.apple.com'
            && in_array($payload['aud'] ?? null, $clientIds, true)
            && ($payload['exp'] ?? 0) >= time();

        if (! $valid) {
            throw ValidationException::withMessages(['identity_token' => ['Invalid or expired Apple token.']]);
        }

        return $payload;
    }

    private function verifyJwtSignature(string $payload, string $signature, array $jwk): bool
    {
        $pem = $this->jwkToPem($jwk);

        return $pem !== null && openssl_verify($payload, $signature, $pem, OPENSSL_ALGO_SHA256) === 1;
    }

    private function jwkToPem(array $jwk): ?string
    {
        if (($jwk['kty'] ?? null) !== 'RSA' || empty($jwk['n']) || empty($jwk['e'])) {
            return null;
        }

        $rsa = $this->asn1Sequence([
            $this->asn1Integer($this->base64UrlDecode($jwk['n'])),
            $this->asn1Integer($this->base64UrlDecode($jwk['e'])),
        ]);
        $publicKey = $this->asn1Sequence([
            $this->asn1Sequence([
                $this->asn1ObjectIdentifier('1.2.840.113549.1.1.1'),
                "\x05\x00",
            ]),
            $this->asn1BitString($rsa),
        ]);

        return "-----BEGIN PUBLIC KEY-----\n"
            .chunk_split(base64_encode($publicKey), 64, "\n")
            ."-----END PUBLIC KEY-----\n";
    }

    private function base64UrlDecode(string $value): string
    {
        return base64_decode(strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4)) ?: '';
    }

    private function asn1Length(int $length): string
    {
        if ($length < 128) {
            return chr($length);
        }
        $bytes = '';
        while ($length > 0) {
            $bytes = chr($length & 0xFF).$bytes;
            $length >>= 8;
        }

        return chr(0x80 | strlen($bytes)).$bytes;
    }

    private function asn1Integer(string $value): string
    {
        $value = ltrim($value, "\x00");
        if ($value === '' || (ord($value[0]) & 0x80)) {
            $value = "\x00".$value;
        }

        return "\x02".$this->asn1Length(strlen($value)).$value;
    }

    private function asn1Sequence(array $items): string
    {
        $value = implode('', $items);

        return "\x30".$this->asn1Length(strlen($value)).$value;
    }

    private function asn1BitString(string $value): string
    {
        $value = "\x00".$value;

        return "\x03".$this->asn1Length(strlen($value)).$value;
    }

    private function asn1ObjectIdentifier(string $oid): string
    {
        $parts = array_map('intval', explode('.', $oid));
        $first = array_shift($parts);
        $second = array_shift($parts);
        $bytes = chr($first * 40 + $second);
        foreach ($parts as $part) {
            $encoded = chr($part & 0x7F);
            $part >>= 7;
            while ($part > 0) {
                $encoded = chr(0x80 | ($part & 0x7F)).$encoded;
                $part >>= 7;
            }
            $bytes .= $encoded;
        }

        return "\x06".$this->asn1Length(strlen($bytes)).$bytes;
    }
}
