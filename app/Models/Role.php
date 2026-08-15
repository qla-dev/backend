<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends BaseModel
{
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
