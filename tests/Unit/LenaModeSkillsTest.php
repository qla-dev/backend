<?php

namespace Tests\Unit;

use App\Services\LegalSourceCatalog;
use App\Services\LenaModeInstructions;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;

class LenaModeSkillsTest extends TestCase
{
    public function test_hs_skill_is_owned_by_hs_and_reused_in_posting_and_storage(): void
    {
        new Application(dirname(__DIR__, 2));
        $loader = new LenaModeInstructions;
        $this->assertFileExists(base_path('agents/lena/skills/hs-detection.md'));
        $this->assertFileDoesNotExist(base_path('agents/lena/hs/hs-detection.md'));
        foreach (['hs', 'post-load', 'storage'] as $mode) {
            $prompt = $loader->for($mode);
            $this->assertSame(1, substr_count($prompt, 'name: hs-detection'));
            // The shared skills overview comes once, before the shared skills it introduces.
            $this->assertSame(1, substr_count($prompt, 'Shared skills (every mode):'));
            $this->assertLessThan(strpos($prompt, 'name: hs-detection'), strpos($prompt, 'Shared skills (every mode):'));
        }
        foreach ([\App\Services\OpenRouterLoadScanner::class, \App\Services\OpenRouterBulkLoadScanner::class] as $class) {
            $reflection = new \ReflectionClass($class);
            $scanner = $reflection->newInstanceWithoutConstructor();
            foreach (['documentSystemPrompt', 'textSystemPrompt'] as $method) {
                $prompt = $reflection->getMethod($method)->invoke($scanner);
                $this->assertStringContainsString('name: hs-detection', $prompt);
            }
        }
    }

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
        // The Sources sections feed the skills screen; legal mode gets the catalogue separately.
        $this->assertStringNotContainsString('## Sources', $instructions);
        $this->assertStringNotContainsString('## Name', $instructions);
        // The legal overview comes first, before the jurisdictions.
        $this->assertLessThan(strpos($instructions, 'Mode instructions (legal, legal-ba):'), strpos($instructions, 'Mode instructions (legal):'));
        $this->assertStringContainsString('9. Confirmations and corrections', $instructions);
        $this->assertStringNotContainsString('legal-eu', (new LenaModeInstructions)->for('general'));
    }

    public function test_general_and_free_hand_tasks_to_their_modes(): void
    {
        new Application(dirname(__DIR__, 2));
        $loader = new LenaModeInstructions;
        foreach (['general', 'free'] as $mode) {
            $prompt = $loader->for($mode);
            foreach (['start_add_yes,start_add_no', 'storage', 'tracking', 'booking', 'hs', 'legal', 'add,storage'] as $options) {
                $this->assertStringContainsString("[[LENA_OPTIONS:{$options}]]", $prompt, "{$mode} mode does not offer {$options}");
            }
        }
    }

    public function test_every_button_a_prompt_offers_is_a_guided_action(): void
    {
        new Application(dirname(__DIR__, 2));
        $actions = (new \App\Services\LenaCatalog)->schema()['actions'];
        foreach (['general', 'free', 'legal', 'post-load', 'storage', 'tracking', 'booking', 'hs', 'about-load'] as $mode) {
            preg_match_all('/\[\[LENA_OPTIONS:([a-z_,]+)\]\]/', (new LenaModeInstructions)->for($mode), $matches);
            foreach ($matches[1] as $list) {
                foreach (explode(',', $list) as $key) {
                    $this->assertContains($key, $actions, "{$mode} mode offers unknown action {$key}");
                }
            }
        }
    }

    public function test_manifest_entries_are_unique_and_stored_files_exist(): void
    {
        new Application(dirname(__DIR__, 2));
        $catalog = new LegalSourceCatalog;
        $ids = array_column($catalog->sources(), 'id');
        $this->assertSame(count($ids), count(array_unique($ids)));
        foreach ($catalog->sources() as $source) {
            $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', $source['id']);
            $this->assertArrayHasKey($source['jurisdiction'], LegalSourceCatalog::JURISDICTIONS);
            if (($source['stored'] ?? true) === false) {
                $this->assertNotEmpty($source['url'], "{$source['id']} opens at the publisher and needs a url");
                continue;
            }
            $this->assertFileExists($catalog->path($source), $source['id']);
        }
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
