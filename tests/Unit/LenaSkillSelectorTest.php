<?php

namespace Tests\Unit;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\AiCallLogger;
use App\Services\LenaSkillCatalog;
use App\Services\LenaSkillSelector;
use App\Services\OpenRouterDispatchAssistant;
use Tests\TestCase;

class LenaSkillSelectorTest extends TestCase
{
    private function conversation(string $answer, string $previous = 'Ask a question'): Conversation
    {
        $conversation = (new Conversation)->setRawAttributes(['id' => 987]);
        return $conversation->setRelation('messages', collect([
            (new Message)->setRawAttributes(['id' => 1, 'sender_user_id' => 9, 'body' => $previous]),
            (new Message)->setRawAttributes(['id' => 2, 'sender_user_id' => 7, 'body' => $answer]),
        ]));
    }

    public function test_catalogue_selection_is_shared_validated_and_loaded(): void
    {
        config(['cache.stores.file' => ['driver' => 'array']]);
        $assistant = $this->createMock(OpenRouterDispatchAssistant::class);
        $assistant->expects($this->once())->method('reply')
            ->with($this->callback(fn ($prompt) => str_contains($prompt, 'reconcile-declaration-ocp-payments') && str_contains($prompt, 'hs-detection')), $this->anything(), 987, false, 'skill_selection')
            ->willReturn('["legal/skills/reconcile-declaration-ocp-payments.md","skills/hs-detection.md","../../.env","unknown","skills/hs-detection.md"]');
        $selector = new LenaSkillSelector(app(LenaSkillCatalog::class), $assistant);
        $conversation = $this->conversation('Provjeri priloge i tarifni broj');
        $turn = ['instructionMode' => 'general', 'canvasEnabled' => false];
        $first = $selector->select($conversation, $turn, 2);
        $this->assertSame($first, $selector->select($conversation, $turn, 2));
        $this->assertSame(['legal/skills/reconcile-declaration-ocp-payments.md', 'skills/hs-detection.md'], $first['files']);
        $this->assertStringContainsString('name: reconcile-declaration-ocp-payments', $selector->instructions($first['files']));
        $this->assertSame(0, AiCallLogger::messageUnits(['service' => 'skill_selection', 'is_success' => true]));
    }

    public function test_expected_guided_answers_do_not_search_but_side_questions_do(): void
    {
        $assistant = $this->createMock(OpenRouterDispatchAssistant::class);
        $assistant->expects($this->never())->method('reply');
        $selector = new LenaSkillSelector(app(LenaSkillCatalog::class), $assistant);
        $turn = ['instructionMode' => 'post-load', 'canvasEnabled' => true];
        foreach ([['weight', '500 kg'], ['title', 'Jabuke Sarajevo Istanbul'], ['customer', 'Trendy d.o.o.'], ['transportType', 'road']] as [$step, $answer]) {
            $this->assertSame(['files' => [], 'guided' => true], $selector->select($this->conversation($answer, '[[LENA_STEP:'.$step.']]'), $turn, 2));
        }
        $this->assertFalse($selector->expectedGuidedAnswer($turn, '[[LENA_STEP:weight]]', 'Objasni kako izračunati CBM'));
        $this->assertFalse($selector->expectedGuidedAnswer($turn, '[[LENA_STEP:notes]]', 'Compare EXW and FOB'));
    }

    public function test_object_shaped_and_fenced_selections_keep_their_skills(): void
    {
        config(['cache.stores.file' => ['driver' => 'array']]);
        $assistant = $this->createMock(OpenRouterDispatchAssistant::class);
        $assistant->method('reply')->willReturn("```json\n[{\"id\":\"post-load/skills/container-recommendation.md\"},{\"name\":\"no id\"}]\n```");
        $selector = new LenaSkillSelector(app(LenaSkillCatalog::class), $assistant);
        $result = $selector->select($this->conversation('ali imaš te informacije u bazi za preporuku kontejnera'), ['instructionMode' => 'general'], 2);
        $this->assertSame(['post-load/skills/container-recommendation.md'], $result['files']);
    }

    public function test_new_catalogue_skills_are_selectable_without_keyword_code(): void
    {
        config(['cache.stores.file' => ['driver' => 'array']]);
        $catalog = $this->createMock(LenaSkillCatalog::class);
        $catalog->method('rows')->willReturn([['id' => 'custom/skills/new-task.md', 'name' => 'New task', 'names' => [], 'description' => 'Review shipment evidence', 'content' => 'Use evidence.']]);
        $assistant = $this->createMock(OpenRouterDispatchAssistant::class);
        $assistant->method('reply')->willReturn('["custom/skills/new-task.md"]');
        $selector = new LenaSkillSelector($catalog, $assistant);
        $result = $selector->select($this->conversation('Review this'), ['instructionMode' => 'general'], 2);
        $this->assertSame(['custom/skills/new-task.md'], $result['files']);
        $this->assertStringContainsString('Use evidence.', $selector->instructions($result['files']));
    }
}
