<?php

namespace App\Http\Controllers\Api;

use App\Models\EmailCampaignRecipient;

class EmailCampaignRecipientController extends CrudController
{
    protected function modelClass(): string
    {
        return EmailCampaignRecipient::class;
    }

    protected function relations(): array
    {
        return ['campaign', 'company', 'user'];
    }

    protected function searchColumns(): array
    {
        return ['email', 'status'];
    }

    protected function rules(bool $u = false): array
    {
        $p = $u ? 'sometimes' : 'required';

        return ['email_campaign_id' => [$p, 'integer', 'exists:email_campaigns,id'], 'company_id' => ['nullable', 'integer', 'exists:companies,id'], 'user_id' => ['nullable', 'integer', 'exists:users,id'], 'email' => [$p, 'email'], 'status' => ['sometimes', 'string', 'max:50'], 'delivered_at' => ['nullable', 'date'], 'opened_at' => ['nullable', 'date']];
    }
}
