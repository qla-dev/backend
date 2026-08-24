<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class SubscriptionPackage extends BaseModel
{
    protected function casts(): array
    {
        return [
            'features' => 'array',
            'is_popular' => 'boolean',
            'is_active' => 'boolean',
            'price_monthly' => 'decimal:2',
        ];
    }

    public function userSubscriptions(): HasMany
    {
        return $this->hasMany(UserSubscription::class);
    }
}
