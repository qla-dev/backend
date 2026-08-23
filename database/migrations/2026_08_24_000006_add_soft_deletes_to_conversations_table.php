<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A conversation is where LenaAI's guided load questionnaire happens, and every AI call it drives
// is separately audited in ai_call_logs (conversation_id there uses nullOnDelete, not cascade).
// Before this, deleting a conversation was a real SQL DELETE, which cascade-deleted every message
// via the FK on messages.conversation_id. Adding deleted_at turns that same delete() call into a
// plain UPDATE - the DB-level cascade never fires, so messages and ai_call_logs both survive
// completely intact, and the conversation itself can still be restored or (via the master-only
// AiCallLogController::purgeConversation) permanently force-deleted later.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
