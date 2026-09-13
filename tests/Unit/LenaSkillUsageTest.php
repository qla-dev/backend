<?php

namespace Tests\Unit;

use App\Services\LenaSkillUsage;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;

class LenaSkillUsageTest extends TestCase
{
    private function turn(array $overrides = []): array
    {
        return [...[
            'instructionMode' => 'general', 'hsMode' => false, 'canvasEnabled' => false,
            'storageMode' => false, 'legalMode' => false, 'explicitPaymentRequest' => false,
        ], ...$overrides];
    }

    public function test_general_mode_names_no_skill(): void
    {
        $this->assertSame([], (new LenaSkillUsage)->files($this->turn(), [], null));
    }

    public function test_each_mode_names_its_own_skill_first(): void
    {
        $usage = new LenaSkillUsage;
        $this->assertSame(['tracking/AGENT.md'], $usage->files($this->turn(['instructionMode' => 'tracking']), [], null));
        $this->assertSame(['hs/AGENT.md', 'skills/hs-detection.md'], $usage->files($this->turn(['instructionMode' => 'hs', 'hsMode' => true]), [], null));
        $this->assertSame(['legal/AGENT.md'], $usage->files($this->turn(['instructionMode' => 'legal', 'legalMode' => true]), [], null));
    }

    public function test_the_turn_adds_the_skills_it_calls_for(): void
    {
        $usage = new LenaSkillUsage;
        $seaDraft = $this->turn(['instructionMode' => 'post-load', 'canvasEnabled' => true]);
        $this->assertSame(
            ['post-load/AGENT.md', 'skills/hs-detection.md', 'post-load/skills/container-recommendation.md'],
            $usage->files($seaDraft, [['hsSearchTerms' => 'olive oil in glass bottles']], 'sea'),
        );
        $this->assertSame(['post-load/AGENT.md'], $usage->files($seaDraft, [['hsCodes' => []]], 'road'));
        // Storage never plans containers, even when the draft still says sea.
        $this->assertSame(['storage/AGENT.md'], $usage->files($this->turn(['instructionMode' => 'storage', 'canvasEnabled' => true, 'storageMode' => true]), [], 'sea'));
        $this->assertSame(
            ['legal/AGENT.md', 'legal/skills/reconcile-declaration-ocp-payments.md'],
            $usage->files($this->turn(['instructionMode' => 'legal', 'legalMode' => true, 'explicitPaymentRequest' => true]), [], null),
        );
    }

    public function test_every_named_file_exists(): void
    {
        new Application(dirname(__DIR__, 2));
        $usage = new LenaSkillUsage;
        $files = [];
        foreach (['legal', 'about-load', 'storage', 'post-load', 'tracking', 'hs', 'booking', 'free'] as $mode) {
            array_push($files, ...$usage->files($this->turn([
                'instructionMode' => $mode, 'hsMode' => true, 'canvasEnabled' => true, 'legalMode' => true, 'explicitPaymentRequest' => true,
            ]), [['hsSearchTerms' => 'steel']], 'rail'));
        }
        foreach (array_unique($files) as $file) {
            $this->assertFileExists(base_path('agents/lena/'.$file));
        }
    }
}
