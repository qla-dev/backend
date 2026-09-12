<?php

namespace Tests\Unit;

use App\Services\LenaModeInstructions;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;

class LenaModeSkillsTest extends TestCase
{
    public function test_named_skill_is_loaded_only_for_its_own_mode(): void
    {
        new Application(dirname(__DIR__, 2));
        $loader = new LenaModeInstructions;
        $this->assertStringContainsString('name: reconcile-declaration-ocp-payments', $loader->for('legal'));
        foreach (['general', 'free', 'tracking', 'booking', 'hs', 'storage', 'post-load', 'about-load'] as $mode) {
            $this->assertDirectoryExists(base_path('agents/lena/'.$mode.'/skills'));
            $this->assertStringNotContainsString('name: reconcile-declaration-ocp-payments', $loader->for($mode));
        }
    }
}
