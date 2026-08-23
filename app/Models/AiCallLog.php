<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiCallLog extends BaseModel
{
    protected function casts(): array
    {
        return [
            'has_attachment' => 'boolean',
            'is_success' => 'boolean',
            'request_payload' => 'array',
            'response_payload' => 'array',
            'cost_usd' => 'decimal:6',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
