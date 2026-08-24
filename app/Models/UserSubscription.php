<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserSubscription extends BaseModel
{
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'started_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subscriptionPackage(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPackage::class);
    }

    /**
     * @throws \Exception If remaining_tokens would go below zero
     */
    public function decrementTokens(int $value): void
    {
        if ($this->remaining_tokens <= 0) {
            throw new \Exception('No remaining LenaAI tokens.');
        }

        $this->decrement('remaining_tokens', $value);
    }

    public function incrementTokens(int $value): void
    {
        $this->increment('remaining_tokens', $value);
    }
}
