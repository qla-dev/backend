<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Review extends BaseModel
{
    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'criteria' => 'array',
        ];
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_user_id');
    }

    public function reviewable(): MorphTo
    {
        return $this->morphTo();
    }
}
