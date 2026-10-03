<?php

namespace Tests\Unit;

use App\Services\LenaImageConfirmation;
use PHPUnit\Framework\TestCase;

class LenaImageConfirmationTest extends TestCase
{
    public function test_request_is_confirmed_even_when_selector_misses_skill(): void
    {
        foreach (['generisi sliku', 'generiši sliku', 'nacrtaj sliku', 'generate an image', 'erstelle ein Bild', 'генериши слику'] as $text) {
            $this->assertTrue(LenaImageConfirmation::required(true, null, $text, []), $text);
        }
    }

    public function test_selected_skill_always_offers_confirmation_in_training(): void
    {
        $skills = ['training/skills/generate-image.md'];
        $this->assertTrue(LenaImageConfirmation::required(true, null, 'Visualise the screen', $skills));
        $this->assertFalse(LenaImageConfirmation::required(false, null, 'generate an image', $skills));
        $this->assertFalse(LenaImageConfirmation::required(true, 'training_image_no', '', $skills));
        $this->assertFalse(LenaImageConfirmation::required(true, 'training_image_yes', '', $skills));
        $this->assertFalse(LenaImageConfirmation::required(true, null, 'Provjeri priložene slike', []));
        $this->assertFalse(LenaImageConfirmation::required(true, null, 'Nemoj generisati sliku', $skills));
        foreach (['bs', 'hr', 'sr', 'de', 'en'] as $lang) {
            $this->assertStringEndsWith('[[LENA_OPTIONS:training_image_yes,training_image_no]]', LenaImageConfirmation::reply($lang));
        }
    }
}
