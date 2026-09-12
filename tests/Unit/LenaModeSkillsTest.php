<?php

namespace Tests\Unit;

use App\Services\LegalSourceCatalog;
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

    public function test_legal_mode_loads_every_jurisdiction(): void
    {
        new Application(dirname(__DIR__, 2));
        $instructions = (new LenaModeInstructions)->for('legal');
        foreach (['legal, legal-ba', 'legal, legal-eu', 'legal, legal-cro', 'legal, legal-srb'] as $label) {
            $this->assertStringContainsString("Mode instructions ({$label}):", $instructions);
        }
        $this->assertStringNotContainsString('legal-eu', (new LenaModeInstructions)->for('general'));
    }

    public function test_every_legal_source_resolves_to_a_local_document(): void
    {
        new Application(dirname(__DIR__, 2));
        $catalog = new LegalSourceCatalog;
        foreach (['eu-union-customs-code' => 'EU', 'hr-import-vat-instruction' => 'HR', 'hr-import-vat-leaflet' => 'HR', 'rs-customs-law' => 'RS', 'rs-vat-law' => 'RS'] as $id => $jurisdiction) {
            $source = $catalog->find($id);
            $this->assertSame($jurisdiction, $source['jurisdiction']);
            $this->assertFileExists($catalog->path($source));
        }
    }
}
