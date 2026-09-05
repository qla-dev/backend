<?php

namespace App\Models;

use App\Models\Concerns\HasReviews;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Customer extends BaseModel
{
    use HasReviews;

    public function scopeFromLoadHistory(Builder $query, User $user): void
    {
        $query->where(function (Builder $customer) use ($user): void {
            $customer->whereNull('customers.user_id')->orWhere('customers.user_id', '!=', $user->id);
        });
        $query->whereExists(function (QueryBuilder $loads) use ($user): void {
            $loads->selectRaw('1')->from('loads')
                ->where(function (QueryBuilder $customer): void {
                    $customer->whereColumn('loads.consignee_customer_id', 'customers.id')
                        ->orWhereColumn('loads.customer_user_id', 'customers.user_id');
                })
                ->where(function (QueryBuilder $history) use ($user): void {
                    $history->where('loads.customer_user_id', $user->id)
                        ->orWhere('loads.assigned_driver_user_id', $user->id)
                        ->orWhereIn('loads.company_id', function (QueryBuilder $companies) use ($user): void {
                            $companies->select('company_id')->from('company_user')
                                ->where('user_id', $user->id)->where('status', 'active');
                        });
                });
        });
    }

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
