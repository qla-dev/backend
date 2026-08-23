<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends BaseModel
{
    // Roles a user must never be able to self-assign or see in a public role picker. 'master'
    // sits above 'superadmin' (same access, plus the AI Stats screen - see User::isSuperAdminOrMaster()).
    public const PROTECTED_NAMES = ['superadmin', 'master'];

    protected function casts(): array
    {
        return ['permissions' => 'array', 'is_active' => 'boolean'];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(CompanyInvitation::class);
    }
}
