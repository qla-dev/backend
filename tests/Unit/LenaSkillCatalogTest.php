<?php

namespace Tests\Unit;

use App\Services\LenaSkillCatalog;
use PHPUnit\Framework\TestCase;

class LenaSkillCatalogTest extends TestCase
{
    public function test_catalog_includes_real_instructions_and_shared_hs_skill_once(): void
    {
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
        }
    }
}
