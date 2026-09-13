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
        $this->assertSame('rs-customs-law', $byId['legal-srb/AGENT.md']['sources'][0]['id']);
        $this->assertSame([], $byId['storage/AGENT.md']['sources']);
    }

    /** Each AGENT.md Sources section lists exactly the manifest entries kept in its folder. */
    public function test_sources_sections_match_the_legal_source_manifest(): void
    {
        new Application(dirname(__DIR__, 2));
        $catalog = new LegalSourceCatalog;
        $byFolder = [];
        foreach ($catalog->sources() as $source) {
            $byFolder[$source['folder']][] = $source['id'];
        }
        foreach (glob(base_path('agents/lena/*/AGENT.md')) as $path) {
            $folder = basename(dirname($path));
            $listed = LenaModeInstructions::split((string) file_get_contents($path))['sources'];
            foreach ($listed as $id) {
                $this->assertNotNull($catalog->find($id), "{$folder}/AGENT.md lists unknown source {$id}");
            }
            $expected = $byFolder[$folder] ?? [];
            sort($expected);
            sort($listed);
            $this->assertSame($expected, $listed, "{$folder}/AGENT.md Sources section differs from legal-sources.json");
        }
    }
}
