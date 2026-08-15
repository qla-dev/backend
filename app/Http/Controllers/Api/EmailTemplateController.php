<?php

namespace App\Http\Controllers\Api;

use App\Models\EmailTemplate;

class EmailTemplateController extends CrudController
{
    protected function modelClass(): string
    {
        return EmailTemplate::class;
    }

    protected function relations(): array
    {
        return ['creator', 'campaigns'];
    }

    protected function searchColumns(): array
    {
        return ['name', 'subject'];
    }

    protected function rules(bool $u = false): array
    {
        $p = $u ? 'sometimes' : 'required';

        return ['created_by_user_id' => [$p, 'integer', 'exists:users,id'], 'name' => [$p, 'string', 'max:150'], 'subject' => [$p, 'string', 'max:255'], 'html_body' => [$p, 'string'], 'design' => ['nullable', 'array'], 'is_active' => ['sometimes', 'boolean']];
    }
}
