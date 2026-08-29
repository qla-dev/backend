<?php

namespace App\Models;

use App\Models\Concerns\HasReviews;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Customer extends BaseModel
{
    use HasReviews;

    protected $appends = ['name', 'email', 'username', 'phone', 'country_code', 'language', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'profile_authorized_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getNameAttribute(): ?string
    {
        return $this->user?->name ?? $this->attributes['name'] ?? null;
    }

    public function getEmailAttribute(): ?string
    {
        return $this->user?->email ?? $this->attributes['email'] ?? null;
    }

    public function getUsernameAttribute(): ?string
    {
        return $this->user?->username;
    }

    public function getPhoneAttribute(): ?string
    {
        return $this->user?->phone ?? $this->attributes['phone'] ?? null;
    }

    public function getCountryCodeAttribute(): ?string
    {
        return $this->user?->country_code ?? $this->attributes['country_code'] ?? null;
    }

    public function getLanguageAttribute(): ?string
    {
        return $this->user?->language;
    }

    public function getIsActiveAttribute(): bool
    {
        return $this->user
            ? (bool) $this->user->is_active && $this->profile_authorized_at !== null
            : false;
    }
}
