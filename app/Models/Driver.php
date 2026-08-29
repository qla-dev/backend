<?php

namespace App\Models;

use App\Models\Concerns\HasReviews;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Driver extends BaseModel
{
    use HasReviews;

    protected $appends = ['name', 'email', 'phone', 'country_code', 'is_active'];

    protected function casts(): array
    {
        return [
            'license_expires_at' => 'date',
            'certifications' => 'array',
            'rating' => 'decimal:2',
            'profile_authorized_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function primaryCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'primary_company_id');
    }

    public function getNameAttribute(): ?string
    {
        return $this->user?->name ?? $this->attributes['name'] ?? null;
    }

    public function getEmailAttribute(): ?string
    {
        return $this->user?->email ?? $this->attributes['email'] ?? null;
    }

    public function getPhoneAttribute(): ?string
    {
        return $this->user?->phone ?? $this->attributes['phone'] ?? null;
    }

    public function getCountryCodeAttribute(): ?string
    {
        return $this->user?->country_code ?? $this->attributes['country_code'] ?? null;
    }

    public function getIsActiveAttribute(): bool
    {
        return $this->user
            ? (bool) $this->user->is_active && $this->profile_authorized_at !== null
            : false;
    }
}
