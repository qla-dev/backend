<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailTemplate extends BaseModel
{
    protected function casts(): array
    {
        return ['design' => 'array', 'is_active' => 'boolean'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(EmailCampaign::class);
    }
}
