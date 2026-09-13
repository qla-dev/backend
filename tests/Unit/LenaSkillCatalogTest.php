<?php

namespace Tests\Unit;

use App\Services\LegalSourceCatalog;
use App\Services\LenaModeInstructions;
use App\Services\LenaSkillCatalog;
use App\Services\LenaSkillResources;
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
        $hs = $byId['skills/hs-detection.md'];
        $this->assertSame('skills', $hs['folder']);
        $this->assertSame('instructions', $hs['kind'], 'A shared skill is a skill of its own, not a subskill');
        $this->assertSame($byId['hs/AGENT.md']['sources'], $hs['sources'], 'HS code detection lists the same resources as the HS mode');
        $this->assertTrue($hs['shared']);
        $this->assertTrue($hs['scanners']);
        $this->assertContains('storage', $hs['modes']);
        $this->assertContains('post-load', $hs['modes']);
        foreach ($rows as $row) {
            $this->assertNotEmpty($row['content']);
            $this->assertNotEmpty($row['description']);
            $this->assertStringNotContainsString('..', $row['id']);
            $this->assertStringNotContainsString('## Sources', $row['content']);
            $this->assertStringNotContainsString('## Name', $row['content']);
            $names = $row['names'];
            ksort($names);
            $this->assertSame(['bs', 'de', 'en'], array_keys($names), "{$row['id']} needs a bs, en and de name");
        }
        $this->assertSame('AI legislativni dispečer', $byId['legal/AGENT.md']['names']['bs']);
        $sharedOverview = $byId['skills/AGENT.md'];
        $this->assertSame(['instructions', 'skills', true], [$sharedOverview['kind'], $sharedOverview['folder'], $sharedOverview['shared']]);
        $this->assertSame('Prepoznavanje HS kodova', $hs['names']['bs']);
        $this->assertSame('rs-customs-law', $byId['legal/legal-srb/AGENT.md']['sources'][0]['id']);
        $this->assertSame('legal/legal-srb', $byId['legal/legal-srb/AGENT.md']['folder']);
        $this->assertSame(['legal'], $byId['legal/legal-srb/AGENT.md']['modes']);
        $ocp = $byId['legal/skills/reconcile-declaration-ocp-payments.md'];
        $this->assertSame(['skill', 'legal', ['legal']], [$ocp['kind'], $ocp['folder'], $ocp['modes']]);
        $this->assertSame(['file'], array_column($byId['storage/AGENT.md']['sources'], 'type'));
        $containers = $byId['post-load/skills/container-recommendation.md']['sources'];
        $this->assertSame('resources/lena/container-types.json', $containers[0]['path'] ?? null, 'Container recommendation lists the container types catalogue');
        $this->assertGreaterThan(0, $containers[0]['bytes']);
        $referenced = app(LenaSkillResources::class)->referencedFiles();
        $this->assertContains('resources/lena/container-types.json', $referenced);
        $this->assertContains('agents/lena/legal-sources.json', $referenced);
        $this->assertFalse(LenaSkillResources::isAllowedFile('resources/lena/../../.env'));
        $this->assertFalse(LenaSkillResources::isAllowedFile('composer.json'));
        $hsTypes = array_column($byId['hs/AGENT.md']['sources'], 'type');
        $this->assertContains('app', $hsTypes);
        $this->assertContains('link', $hsTypes);
        $this->assertContains('legal', $hsTypes);
        $this->assertStringNotContainsString('taric', (new LenaModeInstructions)->for('hs'));
        // Post a load cites web regulations only; its prompt stays a fallback for the guided questionnaire.
        $postLoad = $byId['post-load/AGENT.md'];
        $this->assertSame(['link', 'file'], array_values(array_unique(array_column($postLoad['sources'], 'type'))));
        $this->assertStringContainsString('Guided answers come first', $postLoad['content']);
        $this->assertStringNotContainsString('iccwbo.org', (new LenaModeInstructions)->for('post-load'));
    }

    public function test_every_main_prompt_introduces_lena(): void
    {
        new Application(dirname(__DIR__, 2));
        foreach ((new LenaSkillCatalog)->rows() as $row) {
            if (basename($row['id']) === 'AGENT.md') {
                $this->assertStringStartsWith('You are LenaAI', $row['content'], $row['id']);
            }
        }
    }

    /**
     * Every folder with an AGENT.md keeps its resources in resources/resources.json: valid JSON whose keys name files
     * in that folder and whose entries are well formed. Every legal id exists, and each manifest document is listed by
     * the AGENT.md of the folder that stores it. The md files themselves carry no resources.
     */
    public function test_resources_live_in_each_folders_json(): void
    {
        new Application(dirname(__DIR__, 2));
        $catalog = new LegalSourceCatalog;
        $byFolder = [];
        foreach ($catalog->sources() as $source) {
            $byFolder[$source['folder']][] = $source['id'];
        }
        $this->assertNotEmpty($byFolder);
        $root = base_path('agents/lena');
        $agents = array_merge(glob($root.'/*/AGENT.md') ?: [], glob($root.'/*/*/AGENT.md') ?: []);
        $this->assertNotEmpty($agents);
        foreach ($agents as $agent) {
            $folder = str_replace('\\', '/', substr(dirname($agent), strlen($root) + 1));
            $json = LenaSkillResources::path($folder);
            $this->assertFileExists($json, "{$folder} has no ".LenaSkillResources::FILE);
            $data = json_decode((string) file_get_contents($json), true, 512, JSON_THROW_ON_ERROR);
            $this->assertIsArray($data['resources'] ?? null, "{$folder} resources.json has no resources map");
            $this->assertArrayHasKey('AGENT.md', $data['resources']);
            $listedByAgent = [];
            foreach ($data['resources'] as $file => $entries) {
                $this->assertFileExists(dirname($agent).'/'.$file, "{$folder} resources.json names a missing file {$file}");
                $this->assertIsArray($entries);
                foreach ($entries as $entry) {
                    $type = $entry['type'] ?? null;
                    if ($type === 'legal') {
                        $this->assertNotNull($catalog->find((string) ($entry['id'] ?? '')), "{$folder}/{$file} lists an unknown legal source");
                        if ($file === 'AGENT.md') $listedByAgent[] = $entry['id'];
                    } elseif ($type === 'link') {
                        $this->assertNotEmpty($entry['title'] ?? null, "{$folder}/{$file} has a link without a title");
                        $this->assertStringStartsWith('https://', (string) ($entry['url'] ?? ''));
                    } elseif ($type === 'file') {
                        $this->assertNotEmpty($entry['title'] ?? null, "{$folder}/{$file} has a data file without a title");
                        $this->assertTrue(LenaSkillResources::isAllowedFile((string) ($entry['path'] ?? '')), "{$folder}/{$file} points to a missing or disallowed file");
                    } else {
                        $this->assertSame('app', $type, "{$folder}/{$file} has a resource of unknown type");
                        $this->assertNotEmpty($entry['title'] ?? null, "{$folder}/{$file} has an app screen without a title");
                        $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', (string) ($entry['view'] ?? ''));
                    }
                }
            }
            foreach ($byFolder[$folder] ?? [] as $id) {
                $this->assertContains($id, $listedByAgent, "{$folder} resources.json is missing {$id} from legal-sources.json");
            }
            unset($byFolder[$folder]);
        }
        $this->assertSame([], $byFolder, 'legal-sources.json names folders without an AGENT.md');

        $markdown = array_merge($agents, glob($root.'/*/skills/*.md') ?: [], glob($root.'/*/*/skills/*.md') ?: [], glob($root.'/skills/*.md') ?: []);
        foreach ($markdown as $path) {
            $this->assertStringNotContainsString('## Sources', (string) file_get_contents($path), "{$path} still carries resources");
        }
    }
}
