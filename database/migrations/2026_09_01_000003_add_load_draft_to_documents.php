<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A document can now be filed against a load that has not been published yet.
//
// `documents.load_id` already answers "which load is this paperwork for", and a null there means
// the company archive. But a CMR dropped into LenaAI while a load is still being built belongs to
// neither: there is no load to point at yet, and calling it archive loses the one thing the user
// cares about - that it came with this draft. This column keeps that link, so the Documents page
// can separate paperwork for published loads from paperwork still attached to a draft, and so the
// draft panel can count what has been collected for it.
//
// Nulled rather than cascaded on delete: a discarded draft must not take the uploaded file with it.
// The document simply falls back to being an archive row, which is what it already looks like.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->foreignId('load_draft_id')->nullable()->after('load_id')
                ->constrained('load_drafts')->nullOnDelete()->cascadeOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('load_draft_id');
        });
    }
};
