<?php

namespace Tests\Unit;

use App\Services\LenaLoadConfirmation;
use PHPUnit\Framework\TestCase;

class LenaLoadConfirmationTest extends TestCase
{
    public function test_contextual_yes_opens_the_real_load_canvas_path(): void
    {
        $question = (new \App\Models\Message)->setRawAttributes(['id' => 1, 'sender_user_id' => 99, 'body' => 'Želite li da počnemo kreiranje tereta? [[LENA_OPTIONS:start_add_yes,start_add_no]]']);
        $answer = (new \App\Models\Message)->setRawAttributes(['id' => 2, 'sender_user_id' => 7, 'body' => 'da želim započeti kreiranje novog tereta']);
        $conversation = (new \App\Models\Conversation)->setRawAttributes(['canvas' => false]);
        $conversation->setRelation('freightLoad', null);
        $conversation->setRelation('messages', new \Illuminate\Database\Eloquent\Collection([$question, $answer]));
        $controller = new \App\Http\Controllers\Api\DispatchChatController;
        $method = new \ReflectionMethod($controller, 'resolveTurn');
        $turn = $method->invoke($controller, $conversation, collect([$answer]));
        $this->assertSame('start_add_yes', $turn['guidedAction']);
        $this->assertTrue($turn['canvasEnabled']);
        $this->assertSame('post-load', $turn['instructionMode']);
    }

    public function test_free_text_confirmations_follow_the_pending_action_in_all_languages(): void
    {
        foreach (['yes', 'Yes, please!', 'da želim', 'Da, hoću.', 'Da, hajde!', 'Da, želim, hajde.', 'Да, хајде!', 'Hajde da počnemo', 'Da, može', "Let's start", 'Yes, go ahead', 'da', 'ja bitte', 'Да желим'] as $answer) {
            foreach (['start_add', 'upload', 'continue_add'] as $action) {
                $this->assertSame($action.'_yes', LenaLoadConfirmation::action($answer, "Question? [[LENA_OPTIONS:{$action}_yes,{$action}_no]]"), $answer);
            }
        }
    }

    public function test_denials_do_not_start_a_load_and_unrelated_yes_is_not_a_confirmation(): void
    {
        $question = 'Question? [[LENA_OPTIONS:start_add_yes,start_add_no]]';
        foreach (['no', 'ne hvala', 'ne želim', 'nein danke'] as $answer) {
            $this->assertSame('start_add_no', LenaLoadConfirmation::action($answer, $question));
        }
        $this->assertNull(LenaLoadConfirmation::action('yes', 'Is the pickup in Sarajevo?'));
        $this->assertNull(LenaLoadConfirmation::action('yes, but do not create a load', $question));
        $this->assertNull(LenaLoadConfirmation::action('da ali ne sada', $question));
        $this->assertNull(LenaLoadConfirmation::action('da li treba dokument', $question));
        foreach (['da želim započeti kreiranje novog tereta ali ne sada', 'ne želim započeti kreiranje novog tereta', 'da želim znati kako kreirati novi teret', 'yes create a new load but not now'] as $answer) {
            $this->assertNull(LenaLoadConfirmation::action($answer, $question), $answer);
        }
    }

    public function test_full_sentences_confirm_only_the_pending_load_creation(): void
    {
        foreach (['da želim započeti kreiranje novog tereta', 'Da, želim da dodam novi teret!', 'Да, желим да креирам нови терет.', 'hajde da dodamo novi teret', 'želim kreirati novi teret', 'yes I want to create a new load', 'please start creating a new load', 'Ja, ich möchte eine neue Ladung erstellen'] as $answer) {
            $this->assertSame('start_add_yes', LenaLoadConfirmation::action($answer, '[[LENA_OPTIONS:start_add_yes,start_add_no]]'), $answer);
            $this->assertNull(LenaLoadConfirmation::action($answer, '[[LENA_OPTIONS:upload_yes,upload_no]]'), $answer);
        }
    }

    public function test_guided_voice_skill_belongs_to_post_load_and_all_active_names_parse(): void
    {
        $instructions = new \App\Services\LenaModeInstructions;
        $this->assertStringNotContainsString('Guided voice and text answers', $instructions->shared());
        $skill = \App\Services\LenaModeInstructions::split(file_get_contents(__DIR__.'/../../agents/lena/post-load/skills/guided-voice.md'));
        foreach (['en', 'bs', 'hr', 'sr', 'de'] as $locale) $this->assertNotEmpty($skill['names'][$locale]);
        $files = (new \App\Services\LenaSkillUsage)->files([
            'instructionMode' => 'post-load', 'canvasEnabled' => true, 'hsMode' => false,
            'storageMode' => false, 'legalMode' => false, 'explicitPaymentRequest' => false,
        ], [], 'road');
        $this->assertContains('post-load/skills/guided-voice.md', $files);
    }
}
