<?php

namespace App\Services\Accounting;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class BusinessWorkspace
{
    // Company workspace IDs retain their existing values. Personal IDs fit in a JS safe integer.
    public const PERSONAL_START = 1 << 48;

    public static function personalId(int $userId): int
    {
        return self::PERSONAL_START + $userId;
    }

    public static function isPersonal(int $id): bool
    {
        return $id >= self::PERSONAL_START;
    }

    public static function ownerId(int $id): ?int
    {
        if (self::isPersonal($id)) {
            return $id - self::PERSONAL_START;
        }

        return DB::table('companies')->where('id', $id)->value('owner_user_id');
    }

    public static function personal(User $user): array
    {
        return ['id' => self::personalId($user->id), 'name' => $user->name,
            'owner_user_id' => $user->id, 'personal' => true];
    }

    public static function ensurePersonal(User $user): void
    {
        DB::table('business_workspaces')->insertOrIgnore([
            'id' => self::personalId($user->id), 'user_id' => $user->id,
        ]);
    }

    public static function lock(int $id): void
    {
        DB::table(self::isPersonal($id) ? 'business_workspaces' : 'companies')
            ->where('id', $id)->lockForUpdate()->first();
    }
}
