<?php

namespace Tests\Unit;

use App\Services\LenaLegalSkillIntent;
use App\Services\LenaSkillUsage;
use PHPUnit\Framework\TestCase;

class LenaLegalSkillIntentTest extends TestCase
{
    public function test_latest_conversation_detects_cbm_and_keeps_followups(): void
    {
        $history = ['hoću da izračunam cbm iz paking liste', '[[LENA_ACTION:legal]]'];
        $file = 'legal/skills/calculate-packing-list-cbm.md';
        $this->assertSame($file, LenaLegalSkillIntent::resolve($history));
        array_unshift($history, 'nemam da priložim parking listu Hajde pitaj me pitanje jedno po jedno');
        $this->assertSame($file, LenaLegalSkillIntent::resolve($history));
        array_unshift($history, '315 x 20 x 15 cm, 17 paketa');
        $this->assertSame($file, LenaLegalSkillIntent::resolve($history));
        array_unshift($history, 'Attached packing-list.pdf.');
        $this->assertSame($file, LenaLegalSkillIntent::resolve($history));
    }

    public function test_comparison_takes_priority_over_its_volume_and_new_topics_clear_context(): void
    {
        $this->assertSame('legal/skills/compare-lcl-fcl.md', LenaLegalSkillIntent::resolve(['LCL ili FCL za 300 kg, 1.5 CBM']));
        foreach (['Slobodan razgovor', 'Šta su prekursori?', '[[LENA_ACTION:add]]', 'Ok - sad je dobro'] as $text) {
            $this->assertNull(LenaLegalSkillIntent::resolve([$text, 'Izračunaj CBM']));
        }
    }

    public function test_plain_requests_route_and_name_the_same_skill_without_a_button(): void
    {
        $message = (new \App\Models\Message)->setRawAttributes(['id' => 1, 'sender_user_id' => 7, 'body' => 'Calculate CBM from packing list']);
        $conversation = (new \App\Models\Conversation)->setRawAttributes(['canvas' => false]);
        $conversation->setRelation('messages', collect([$message]))->setRelation('freightLoad', null);
        $controller = new \App\Http\Controllers\Api\DispatchChatController;
        $turn = (new \ReflectionMethod($controller, 'resolveTurn'))->invoke($controller, $conversation, collect([$message]));
        $this->assertSame('legal', $turn['instructionMode']);
        $this->assertFalse($turn['canvasEnabled']);
        $this->assertFalse($turn['detectedLoadCreationRequest']);
        $this->assertSame(['legal/AGENT.md', 'legal/skills/calculate-packing-list-cbm.md'], (new LenaSkillUsage)->files($turn, [], null));
    }
}
