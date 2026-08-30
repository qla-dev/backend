<?php

namespace Tests\Unit;

use App\Services\CustomsDocumentCatalog;
use Tests\TestCase;

class CustomsDocumentCatalogTest extends TestCase
{
    public function test_it_exposes_the_full_deklarant_catalog(): void
    {
        $catalog = app(CustomsDocumentCatalog::class)->catalog();

        $this->assertCount(160, $catalog);
        $this->assertSame('AGL', $catalog[0]['code']);
        $this->assertArrayNotHasKey('tariffs', $catalog[0]);
    }

    public function test_it_matches_standard_and_hs_linked_documents(): void
    {
        $documents = app(CustomsDocumentCatalog::class)->matching(['0407 29 90 00']);
        $codes = array_column($documents, 'code');

        foreach (['DIS', 'OSI', 'N380', 'PZT', 'DV1'] as $standardCode) {
            $this->assertContains($standardCode, $codes);
        }
        foreach (['AGL', 'N851', 'N852', 'N003', 'N853'] as $matchedCode) {
            $this->assertContains($matchedCode, $codes);
        }
    }

    public function test_it_uses_prefix_matching_for_more_specific_hs_codes(): void
    {
        $codes = array_column(
            app(CustomsDocumentCatalog::class)->matching(['0407999999']),
            'code',
        );

        $this->assertContains('AGL', $codes);
    }

    public function test_only_supported_templates_are_downloadable(): void
    {
        $catalog = collect(app(CustomsDocumentCatalog::class)->catalog())->keyBy('code');

        $this->assertTrue($catalog['DIS']['downloadable']);
        $this->assertTrue($catalog['DV1']['downloadable']);
        $this->assertFalse($catalog['N380']['downloadable']);
    }
}
