<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoadNote extends BaseModel
{
    protected function casts(): array
    {
        return ['is_private' => 'boolean'];
    }

    public function freightLoad(): BelongsTo
    {
        return $this->belongsTo(Load::class, 'load_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }
}
