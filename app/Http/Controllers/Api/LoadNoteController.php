<?php

namespace App\Http\Controllers\Api;

use App\Models\LoadNote;

class LoadNoteController extends CrudController
{
    protected function modelClass(): string
    {
        return LoadNote::class;
    }

    protected function relations(): array
    {
        return ['freightLoad', 'author'];
    }

    protected function rules(bool $u = false): array
    {
        $p = $u ? 'sometimes' : 'required';

        return ['load_id' => [$p, 'integer', 'exists:loads,id'], 'author_user_id' => [$p, 'integer', 'exists:users,id'], 'priority' => ['sometimes', 'in:low,medium,high'], 'body' => [$p, 'string'], 'is_private' => ['sometimes', 'boolean']];
    }
}
