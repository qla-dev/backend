<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Repairs conversations whose last_message_at was written from a client UTC timestamp.
 *
 * Every caller sends last_message_at as a plain new Date().toISOString(), and Eloquent's datetime
 * cast stores whatever timezone a parsed string carries instead of re-localizing it to
 * config('app.timezone') - so the column landed two hours behind the created_at written beside it
 * in the very same request. The conversation sidebar reads this column, which is why those threads
 * showed a time two hours in the past. ConversationController now overrides the client value with
 * now(), so only rows written before that fix need correcting.
 *
 * A row is identifiable because last_message_at precedes its own created_at, which cannot happen
 * legitimately: the column is set when the conversation is created and only ever moves forward with
 * each message. The repaired value is the newest message's sent_at, or created_at for a thread that
 * never received one.
 */
return new class extends Migration
{
    public function up(): void
    {
        $broken = DB::table('conversations')
            ->whereNotNull('last_message_at')
            ->whereColumn('last_message_at', '<', 'created_at')
            ->pluck('created_at', 'id');

        foreach ($broken as $id => $createdAt) {
            $newestMessageAt = DB::table('messages')
                ->where('conversation_id', $id)
                ->max('sent_at');

            DB::table('conversations')->where('id', $id)->update([
                'last_message_at' => $newestMessageAt && $newestMessageAt > $createdAt
                    ? $newestMessageAt
                    : $createdAt,
            ]);
        }
    }

    public function down(): void
    {
        // The original values were wrong by construction, so there is nothing worth restoring.
    }
};
