<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends BaseModel
{
    protected function casts(): array
    {
        return ['attachments' => 'array', 'sent_at' => 'datetime', 'edited_at' => 'datetime'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }
}
