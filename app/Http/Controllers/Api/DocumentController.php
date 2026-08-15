<?php

namespace App\Http\Controllers\Api;

use App\Models\Document;

class DocumentController extends CrudController
{
    protected function modelClass(): string
    {
        return Document::class;
    }

    protected function relations(): array
    {
        return ['freightLoad', 'vehicle', 'user', 'uploader'];
    }

    protected function searchColumns(): array
    {
        return ['name', 'type'];
    }

    protected function rules(bool $u = false): array
    {
        $p = $u ? 'sometimes' : 'required';

        return ['load_id' => ['nullable', 'integer', 'exists:loads,id'], 'vehicle_id' => ['nullable', 'integer', 'exists:vehicles,id'], 'user_id' => ['nullable', 'integer', 'exists:users,id'], 'uploaded_by_user_id' => [$p, 'integer', 'exists:users,id'], 'type' => [$p, 'string', 'max:100'], 'name' => [$p, 'string', 'max:255'], 'path' => [$p, 'string', 'max:500'], 'mime_type' => ['nullable', 'string', 'max:120'], 'size_bytes' => ['nullable', 'integer', 'min:0'], 'expires_at' => ['nullable', 'date']];
    }
}
