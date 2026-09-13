<?php

namespace Tests\Unit;

use App\Services\LegalSourceCatalog;
use App\Services\LenaModeInstructions;
use App\Services\LenaSkillCatalog;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;

class LenaSkillCatalogTest extends TestCase
{
    public function test_catalog_includes_real_instructions_and_shared_hs_skill_once(): void
    {
        new Application(dirname(__DIR__, 2));
        $rows = (new LenaSkillCatalog)->rows();
        $ids = array_column($rows, 'id');
        $this->assertSame(count($ids), count(array_unique($ids)));
        $byId = array_column($rows, null, 'id');
        $this->assertSame('instructions', $byId['storage/AGENT.md']['kind']);
        $this->assertSame('skill', $byId['post-load/skills/container-recommendation.md']['kind']);
        $hs = $byId['hs/hs-detection.md'];
        $this->assertTrue($hs['shared']);
        $this->assertTrue($hs['scanners']);
        $this->assertContains('storage', $hs['modes']);
        $this->assertContains('post-load', $hs['modes']);
        foreach ($rows as $row) {
            $this->assertNotEmpty($row['content']);
            $this->assertNotEmpty($row['description']);
            $this->assertStringNotContainsString('..', $row['id']);
            $this->assertStringNotContainsString('## Sources', $row['content']);
        }
        $this->assertSame('rs-customs-law', $byId['legal/legal-srb/AGENT.md']['sources'][0]['id']);
        $this->assertSame('legal/legal-srb', $byId['legal/legal-srb/AGENT.md']['folder']);
        $this->assertSame(['legal'], $byId['legal/legal-srb/AGENT.md']['modes']);
        $ocp = $byId['legal/skills/reconcile-declaration-ocp-payments.md'];
        $this->assertSame(['skill', 'legal', ['legal']], [$ocp['kind'], $ocp['folder'], $ocp['modes']]);
        $this->assertSame([], $byId['storage/AGENT.md']['sources']);
        $hsTypes = array_column($byId['hs/AGENT.md']['sources'], 'type');
        $this->assertContains('app', $hsTypes);
        $this->assertContains('link', $hsTypes);
        $this->assertContains('legal', $hsTypes);
        $this->assertStringNotContainsString('taric', (new LenaModeInstructions)->for('hs'));
    }

    public function test_every_main_prompt_introduces_lena(): void
    {
        new Application(dirname(__DIR__, 2));
        foreach ((new LenaSkillCatalog)->rows() as $row) {
            if ($row['kind'] === 'instructions') {
                $this->assertStringStartsWith('You are LenaAI', $row['content'], $row['id']);
            }
        }
    }

    /**
     * Every Sources line parses, every legal id exists, and each manifest entry is listed by the AGENT.md
     * of the folder that stores it. Other folders may cite the same document too.
     */
    public function test_sources_sections_match_the_legal_source_manifest(): void
    {
        new Application(dirname(__DIR__, 2));
        $catalog = new LegalSourceCatalog;
        $byFolder = [];
        foreach ($catalog->sources() as $source) {
            $byFolder[$source['folder']][] = $source['id'];
        }
        $root = base_path('agents/lena');
        $paths = array_merge(glob($root.'/*/AGENT.md') ?: [], glob($root.'/*/*/AGENT.md') ?: []);
        $this->assertNotEmpty($byFolder);
        foreach ($paths as $path) {
            $folder = str_replace('\\', '/', substr(dirname($path), strlen($root) + 1));
            $content = (string) file_get_contents($path);
            $entries = LenaModeInstructions::split($content)['sources'];
            preg_match_all('/^\s*-\s/m', preg_split('/^##\s+Sources\s*$/mi', $content, 2)[1] ?? '', $lines);
            $this->assertCount(count($lines[0]), $entries, "{$folder}/AGENT.md has a Sources line that does not parse");
            $legal = [];
            foreach ($entries as $entry) {
                if ($entry['type'] === 'legal') {
                    $this->assertNotNull($catalog->find($entry['id']), "{$folder}/AGENT.md lists unknown source {$entry['id']}");
                    $legal[] = $entry['id'];
                } elseif ($entry['type'] === 'link') {
                    $this->assertStringStartsWith('https://', $entry['url']);
                } else {
                    $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', $entry['view']);
                }
            }
            foreach ($byFolder[$folder] ?? [] as $id) {
                $this->assertContains($id, $legal, "{$folder}/AGENT.md Sources section is missing {$id} from legal-sources.json");
            }
            unset($byFolder[$folder]);
        }
        $this->assertSame([], $byFolder, 'legal-sources.json names folders without an AGENT.md');
    }
}
