<?php

namespace App\Http\Controllers\Api;

use App\Models\AiCallLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class AiCallLogController extends CrudController
{
    protected function modelClass(): string
    {
        return AiCallLog::class;
    }

    protected function rules(bool $updating = false): array
    {
        // Read-only from the API (see routes/api.php: only index/show are registered) - rows are
        // written exclusively by AiCallLogger. Kept minimal just to satisfy the abstract contract.
        return [];
    }

    protected function relations(): array
    {
        return ['conversation', 'user'];
    }

    protected function searchColumns(): array
    {
        return ['service', 'model', 'error_message'];
    }

    protected function applyFilters(Builder $query, Request $request): void
    {
        $service = trim((string) $request->query('service', ''));
        if ($service !== '') {
            $query->where('service', $service);
        }

        if ($request->query('has_attachment') !== null) {
            $query->where('has_attachment', $request->boolean('has_attachment'));
        }

        if ($request->query('is_success') !== null) {
            $query->where('is_success', $request->boolean('is_success'));
        }

        $conversationId = $request->query('conversation_id');
        if ($conversationId !== null && $conversationId !== '') {
            $query->where('conversation_id', (int) $conversationId);
        }
    }
}
