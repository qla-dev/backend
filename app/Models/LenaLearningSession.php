<?php

namespace App\Models;

class LenaLearningSession extends BaseModel
{
    protected function casts(): array
    {
        return ['state' => 'array', 'version' => 'integer', 'last_user_message_id' => 'integer', 'reply_message_id' => 'integer'];
    }
}
