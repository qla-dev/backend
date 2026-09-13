<?php
namespace Tests\Unit;

use App\Models\Message;
use App\Services\LenaLoadQuestionnaire;
use PHPUnit\Framework\TestCase;

class LenaGuidedSkipTest extends TestCase
{
    public function test_spoken_skip_resolves_only_the_pending_step(): void
    {
        $method = new \ReflectionMethod(LenaLoadQuestionnaire::class, 'negativeAnswersByStep');
        foreach (['odabrati kasnije', 'Odaberi kasnije!', 'одабери касније', 'choose later', 'später auswählen'] as $text) {
            $question = (new Message)->setRawAttributes(['sender_user_id' => 9, 'body' => '[[LENA_STEP:customer]]']);
            $answer = (new Message)->setRawAttributes(['sender_user_id' => 2, 'body' => $text]);
            $this->assertSame(['customer' => true], $method->invoke(new LenaLoadQuestionnaire, collect([$question, $answer]), 9));
            $this->assertSame([], $method->invoke(new LenaLoadQuestionnaire, collect([$answer]), 9));
        }
        $question = (new Message)->setRawAttributes(['sender_user_id' => 9, 'body' => '[[LENA_STEP:customer]]']);
        $answer = (new Message)->setRawAttributes(['sender_user_id' => 2, 'body' => 'nemoj odabrati kasnije']);
        $this->assertSame([], $method->invoke(new LenaLoadQuestionnaire, collect([$question, $answer]), 9));
    }
}
