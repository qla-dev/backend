<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailCampaignRecipient extends BaseModel
{
    protected function casts(): array
    {
        return ['delivered_at' => 'datetime', 'opened_at' => 'datetime'];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(EmailCampaign::class, 'email_campaign_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
