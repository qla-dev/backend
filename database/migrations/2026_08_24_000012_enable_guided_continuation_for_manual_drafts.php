<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $greetings = [
            'Čestitamo, kreirali ste draft tereta%',
            'Herzlichen Glückwunsch, Sie haben einen Ladungsentwurf%',
            'Congratulations, you created a load draft%',
        ];

        DB::table('conversations')
            ->whereNotNull('load_draft_id')
            ->whereNull('load_id')
            ->orderBy('id')
            ->chunkById(200, function ($conversations) use ($greetings): void {
                foreach ($conversations as $conversation) {
                    $message = DB::table('messages')
                        ->where('conversation_id', $conversation->id)
                        ->where(function ($query) use ($greetings): void {
                            foreach ($greetings as $greeting) {
                                $query->orWhere('body', 'like', $greeting);
                            }
                        })
                        ->oldest('id')
                        ->first();

                    if (! $message) {
                        continue;
                    }

                    $body = match (true) {
                        str_starts_with($message->body, 'Čestitamo') => "Čestitamo, kreirali ste draft tereta! Sačuvani podaci su učitani u Draft Panel. Želite li sada nastaviti vođeno popunjavanje?\n\n[[LENA_OPTIONS:continue_add_yes,continue_add_no]]",
                        str_starts_with($message->body, 'Herzlichen') => "Ihr Ladungsentwurf wurde erstellt. Die gespeicherten Daten wurden in den Entwurfsbereich geladen. Möchten Sie jetzt mit der geführten Eingabe fortfahren?\n\n[[LENA_OPTIONS:continue_add_yes,continue_add_no]]",
                        default => "Your load draft was created. Its saved data is loaded in the Draft panel. Would you like to continue the guided form now?\n\n[[LENA_OPTIONS:continue_add_yes,continue_add_no]]",
                    };

                    DB::table('conversations')->where('id', $conversation->id)->update(['canvas' => true]);
                    DB::table('messages')->where('id', $message->id)->update(['body' => $body]);
                }
            });
    }

    public function down(): void
    {
        // The previous free-form greeting and canvas state cannot be reconstructed safely.
    }
};
