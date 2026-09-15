<?php

namespace Tests\Unit;

use App\Services\LenaCatalog;
use App\Services\LenaModeInstructions;
use App\Services\LenaSkillCatalog;
use App\Services\OpenRouterImageGenerator;
use Tests\TestCase;

class LenaTrainingModeTest extends TestCase
{
    public function test_training_mode_loads_its_subskills_and_no_other_mode_does(): void
    {
        $loader = new LenaModeInstructions;
        $training = $loader->for('training');
        $this->assertStringContainsString('Mode instructions (training):', $training);
        $this->assertStringContainsString('[[LENA_PICK:conversation]]', $training, 'Referring to a conversation offers the picker');
        foreach (['make-a-feature', 'training-skill', 'generate-image', 'refer-to-conversation'] as $skill) {
            $this->assertSame(1, substr_count($training, "name: {$skill}"), $skill);
            foreach (['general', 'free', 'legal', 'post-load', 'storage', 'tracking', 'booking', 'hs', 'about-load'] as $mode) {
                $this->assertStringNotContainsString("name: {$skill}", $loader->for($mode), "{$mode} must not load {$skill}");
            }
        }
        $this->assertStringNotContainsString('## Name', $training);
    }

    public function test_training_is_offered_right_after_legal_and_its_buttons_are_actions(): void
    {
        $schema = (new LenaCatalog)->schema();
        $welcome = $schema['welcome_actions'];
        $this->assertSame(array_search('legal', $welcome, true) + 1, array_search('training', $welcome, true));
        preg_match_all('/\[\[LENA_OPTIONS:([a-z_,]+)\]\]/', (new LenaModeInstructions)->for('training'), $matches);
        $this->assertNotEmpty($matches[1], 'The image skill offers its permission buttons');
        foreach (explode(',', implode(',', $matches[1])) as $key) {
            $this->assertContains($key, $schema['actions']);
        }
        foreach (['en', 'de', 'bs', 'hr', 'sr'] as $locale) {
            foreach (['training', 'training_image_yes', 'training_image_no'] as $action) {
                $this->assertNotEmpty((new LenaCatalog)->text($locale)['actions'][$action] ?? null, "{$locale} needs a label for {$action}");
            }
        }
    }

    public function test_catalog_lists_training_as_a_skill_with_its_subskills(): void
    {
        $rows = collect(app(LenaSkillCatalog::class)->rows())->keyBy('id');
        $this->assertSame(['instructions', ['training']], [$rows['training/AGENT.md']['kind'], $rows['training/AGENT.md']['modes']]);
        $this->assertSame('AI trening', $rows['training/AGENT.md']['names']['bs']);
        foreach (['make-a-feature', 'training-skill', 'generate-image', 'refer-to-conversation'] as $skill) {
            $row = $rows["training/skills/{$skill}.md"];
            $this->assertSame(['skill', 'training'], [$row['kind'], $row['folder']]);
            $this->assertNotEmpty($row['description']);
            $this->assertSame([], array_diff(['bs', 'en', 'de'], array_keys($row['names'])));
        }
    }

    public function test_generated_image_data_url_is_decoded_and_anything_else_is_rejected(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFBQIAX8jx0gAAAABJRU5ErkJggg==');
        $decoded = OpenRouterImageGenerator::decode('data:image/png;base64,'.base64_encode($png));
        $this->assertSame(['bytes' => $png, 'mime' => 'image/png', 'extension' => 'png'], $decoded);
        $this->assertSame('jpg', OpenRouterImageGenerator::decode('data:image/jpeg;base64,'.base64_encode('x'))['extension']);
        foreach ([null, '', 'https://example.com/a.png', 'data:text/html;base64,PGI+', 'data:image/png;base64,***'] as $invalid) {
            $this->assertNull(OpenRouterImageGenerator::decode($invalid));
        }
    }
}
