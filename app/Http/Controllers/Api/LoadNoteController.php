<?php

namespace App\Http\Controllers\Api;

use App\Models\LoadNote;

class LoadNoteController extends CrudController
{
    private const NOTE_TYPES = [
        'LOADING_INSTRUCTIONS',
        'UNLOADING_INSTRUCTIONS',
        'LOADING_CONTACT',
        'UNLOADING_CONTACT',
        'DOCK_INSTRUCTIONS',
        'PAPERWORK',
        'CUSTOMS',
        'ADR',
        'PALLET_EXCHANGE',
        'DRIVER_INSTRUCTIONS',
        'DISPATCH_INSTRUCTIONS',
        'ROUTE_REMARK',
        'CUSTOMER_NOTE',
        'DELIVERY_REQUIREMENT',
        'PICKUP_REQUIREMENT',
        'WAITING_TIME',
        'DELAY',
        'BREAKDOWN',
        'OTHER',
    ];

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

        return ['load_id' => [$p, 'integer', 'exists:loads,id'], 'author_user_id' => [$p, 'integer', 'exists:users,id'], 'note_type' => ['sometimes', 'string', 'in:'.implode(',', self::NOTE_TYPES)], 'priority' => ['sometimes', 'in:low,medium,high'], 'body' => [$p, 'string'], 'is_private' => ['sometimes', 'boolean']];
    }
}
