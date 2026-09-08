<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Document extends BaseModel
{
    protected static function booted(): void
    {
        static::saving(function (Document $document): void {
            if ($document->load_id && ($document->isDirty('load_id') || $document->isDirty('type') || $document->isDirty('path'))
                && in_array(strtolower($document->type ?? ''), ['pod', 'proof_of_delivery'], true)) {
                $load = Load::findOrFail($document->load_id);
                if (!$load->for_storage) \App\Services\ChecklistStatusRequirements::assertTaskAllowed($load, ['key' => 'proof_of_delivery']);
            }
        });
    }

    protected function casts(): array
    {
        return ['expires_at' => 'date'];
    }

    public function freightLoad(): BelongsTo
    {
        return $this->belongsTo(Load::class, 'load_id');
    }

    /** Set while the paperwork belongs to a load that has not been published yet. */
    public function loadDraft(): BelongsTo
    {
        return $this->belongsTo(LoadDraft::class, 'load_draft_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
