<?php

namespace App\Http\Controllers\Api;

use App\Models\EmailCampaign;

class EmailCampaignController extends CrudController
{
    protected function modelClass(): string
    {
        return EmailCampaign::class;
    }

    protected function relations(): array
    {
        return ['template', 'creator', 'recipients.company', 'recipients.user'];
    }

    protected function searchColumns(): array
    {
        return ['name', 'status'];
    }

    protected function rules(bool $u = false): array
    {
        $p = $u ? 'sometimes' : 'required';

        return ['email_template_id' => [$p, 'integer', 'exists:email_templates,id'], 'created_by_user_id' => [$p, 'integer', 'exists:users,id'], 'name' => [$p, 'string', 'max:150'], 'status' => ['sometimes', 'string', 'max:50'], 'scheduled_at' => ['nullable', 'date'], 'sent_at' => ['nullable', 'date']];
    }
}
