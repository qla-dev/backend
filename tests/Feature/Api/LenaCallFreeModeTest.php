<?php

namespace Tests\Feature\Api;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\LenaCallTranscript;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What a call is supposed to do to the conversation it owns.
 *
 * These cover the parts that broke repeatedly: a call placed from nothing must land in free roam
 * rather than opening a load questionnaire, and both sides of the talking must end up in the
 * thread, attributed correctly, because in free roam nothing else writes it.
 */
class LenaCallFreeModeTest extends TestCase
{
    use RefreshDatabase;

    private function dispatcher(): User
    {
        return User::factory()->create(['username' => 'ai_dispatcher']);
    }

    private function conversationFor(User $caller): Conversation
    {
        $conversation = Conversation::query()->create([
            'created_by_user_id' => $caller->id,
            'channel' => 'inapp',
            'subject' => 'LenaAI',
            'last_message_at' => now(),
        ]);
        $conversation->participants()->attach($caller->id);

        return $conversation;
    }



    /** The mode a conversation is in: the last mode marker in its messages. */
    private function modeOf(Conversation $conversation): ?string
    {
        foreach ($conversation->messages()->orderByDesc('id')->pluck('body') as $body) {
            if (preg_match('/^\[\[LENA_ACTION:([a-z_]+)\]\]$/', trim((string) $body), $match)) return $match[1];
        }

        return null;
    }
    public function test_both_sides_of_the_call_are_saved_and_attributed_correctly(): void
    {
        $caller = User::factory()->create();
        $dispatcher = $this->dispatcher();
        $conversation = $this->conversationFor($caller);
        $transcript = app(LenaCallTranscript::class);

        $this->assertTrue($transcript->save($conversation->id, 'caller', 'Treba mi kamion za Beč.', $caller->id));
        $this->assertTrue($transcript->save($conversation->id, 'lena', 'Za kad vam treba?', $caller->id));

        $saved = $conversation->messages()->orderBy('id')->get(['sender_user_id', 'body']);

        $this->assertCount(2, $saved);
        $this->assertSame($caller->id, $saved[0]->sender_user_id);
        $this->assertSame('Treba mi kamion za Beč.', $saved[0]->body);
        // Lena's side must be attributed to the dispatcher account, never to the caller - the app
        // sends a speaker flag and the server decides, so a client cannot post as Lena.
        $this->assertSame($dispatcher->id, $saved[1]->sender_user_id);
        $this->assertSame('Za kad vam treba?', $saved[1]->body);
    }

    public function test_a_repeated_transcript_turn_is_not_stored_twice(): void
    {
        $caller = User::factory()->create();
        $this->dispatcher();
        $conversation = $this->conversationFor($caller);
        $transcript = app(LenaCallTranscript::class);

        // The realtime API re-sends a turn's transcript on reconnect.
        $this->assertTrue($transcript->save($conversation->id, 'caller', 'Halo?', $caller->id));
        $this->assertFalse($transcript->save($conversation->id, 'caller', 'Halo?', $caller->id));

        $this->assertSame(1, $conversation->messages()->count());
    }

    public function test_an_empty_or_noise_turn_is_not_stored(): void
    {
        $caller = User::factory()->create();
        $this->dispatcher();
        $conversation = $this->conversationFor($caller);
        $transcript = app(LenaCallTranscript::class);

        $this->assertFalse($transcript->save($conversation->id, 'caller', '   ', $caller->id));
        $this->assertFalse($transcript->save($conversation->id, 'caller', 'a', $caller->id));

        $this->assertSame(0, $conversation->messages()->count());
    }


    public function test_a_call_thread_opens_in_free_roam_not_in_load_creation(): void
    {
        $caller = User::factory()->create();
        $conversation = $this->conversationFor($caller);

        // Exactly what lenaCallDelegate writes the moment it creates a thread for a call.
        Message::query()->create([
            'conversation_id' => $conversation->id,
            'sender_user_id' => $caller->id,
            'body' => '[[LENA_ACTION:freeroam]]',
            'sent_at' => now(),
        ]);

        $this->assertSame('freeroam', $this->modeOf($conversation));
        $this->assertFalse((bool) $conversation->fresh()->canvas, 'A call thread must not start with the load canvas open.');
        $this->assertNull($conversation->fresh()->load_draft_id, 'A call thread must not start with a load draft.');
    }

    public function test_free_roam_and_ask_about_freightbook_are_separate_actions(): void
    {
        $this->assertNotSame(
            __('lena.actions.free', [], 'bs'),
            __('lena.actions.freeroam', [], 'bs'),
            'The two chips must not share a label.',
        );

        $expected = [
            'bs' => ['free' => 'Pitanja o Freightbook.ai', 'freeroam' => 'Slobodan razgovor'],
            'en' => ['free' => 'Ask about Freightbook.ai', 'freeroam' => 'Free roam chat'],
            'de' => ['free' => 'Fragen zu Freightbook.ai', 'freeroam' => 'Freies Gespräch'],
        ];

        foreach ($expected as $locale => $labels) {
            foreach ($labels as $action => $label) {
                $this->assertSame($label, __("lena.actions.{$action}", [], $locale), "Wrong {$action} label in {$locale}.");
            }
        }
    }

    public function test_free_roam_is_offered_on_the_welcome_and_loads_its_own_prompt(): void
    {
        $schema = json_decode((string) file_get_contents(base_path('resources/lena/schema.json')), true);
        $this->assertContains('freeroam', $schema['welcome_actions'], 'Free roam must be offered as a welcome chip.');

        $prompt = app(\App\Services\LenaModeInstructions::class)->for('freeroam');
        $this->assertStringContainsString('MASTER SKILL', $prompt, 'Free roam must load its own gateway prompt.');
        $this->assertStringNotContainsString('{language}', $prompt);
    }

    public function test_free_roam_is_a_registered_action_not_just_a_welcome_chip(): void
    {
        $catalog = json_decode(
            app(\App\Http\Controllers\Api\LenaCatalogController::class)
                ->show(app(\App\Services\LenaCatalog::class))
                ->getContent(),
            true,
        )['data'];

        // The welcome row is built by imploding welcome_actions into a LENA_OPTIONS line, so a chip
        // can be offered while the key itself is unregistered - which is exactly what happened when
        // this was added to one list and not the other.
        $this->assertContains('freeroam', $catalog['actions'], 'freeroam must be a registered action.');
        $this->assertSame('freeroam', $catalog['welcome_actions'][0] ?? null);

        preg_match('/\[\[LENA_OPTIONS:([^\]]+)\]\]/', $catalog['locales']['bs']['welcome']['general'], $match);
        $this->assertNotEmpty($match, 'The welcome must carry an options line.');
        $this->assertStringStartsWith('freeroam,', $match[1]);

        foreach (['bs' => 'Slobodan razgovor', 'en' => 'Free roam chat', 'de' => 'Freies Gespräch'] as $locale => $label) {
            $this->assertSame($label, $catalog['locales'][$locale]['actions']['freeroam'] ?? null);
            $this->assertNotSame(
                $catalog['locales'][$locale]['actions']['free'] ?? null,
                $catalog['locales'][$locale]['actions']['freeroam'] ?? null,
                "The two chips share a label in {$locale}.",
            );
        }
    }
}
