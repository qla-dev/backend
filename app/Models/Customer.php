<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Customer extends BaseModel
{
    protected $appends = ['name', 'email', 'username', 'phone', 'country_code', 'language', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getNameAttribute(): ?string
    {
        return $this->user?->name;
    }

    public function getEmailAttribute(): ?string
    {
        return $this->user?->email;
    }

    public function getUsernameAttribute(): ?string
    {
        return $this->user?->username;
    }

    public function getPhoneAttribute(): ?string
    {
        return $this->user?->phone;
    }

    public function getCountryCodeAttribute(): ?string
    {
        return $this->user?->country_code;
    }

    public function getLanguageAttribute(): ?string
    {
        return $this->user?->language;
    }

    public function getIsActiveAttribute(): bool
    {
        return (bool) $this->user?->is_active;
    }
}
