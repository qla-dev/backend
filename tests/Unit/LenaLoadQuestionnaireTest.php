<?php

namespace Tests\Unit;

use App\Models\Message;
use App\Services\LenaLoadQuestionnaire;
use Illuminate\Support\Collection;
use Tests\TestCase;

class LenaLoadQuestionnaireTest extends TestCase
{
    public function test_it_starts_with_the_first_missing_scan_field(): void
    {
        $next = (new LenaLoadQuestionnaire)->nextStep([], collect(), 99);

        $this->assertSame('title', $next['key']);
    }

    public function test_storage_flow_chooses_destination_and_owned_warehouse_before_load_fields(): void
    {
        $questionnaire = new LenaLoadQuestionnaire;

        $target = $questionnaire->nextStep(['transportType' => 'warehouse'], collect(), 99);
        $warehouse = $questionnaire->nextStep([
            'transportType' => 'warehouse',
            'storageTarget' => 'own',
        ], collect(), 99);
        $exchange = $questionnaire->nextStep([
            'transportType' => 'warehouse',
            'storageTarget' => 'exchange',
        ], collect(), 99);

        $this->assertSame('storageTarget', $target['key']);
        $this->assertSame('warehouse', $warehouse['key']);
        $this->assertSame('title', $exchange['key']);
    }

    public function test_it_follows_scan_field_order_and_accepts_explicit_none(): void
    {
        $draft = [
            'title' => 'Steel',
            'transportType' => 'road',
            'goodsType' => 'Steel coils',
            'weightKg' => 12000,
        ];
        $messages = new Collection([
            $this->message(99, 'How many pallets? [[LENA_STEP:pallets]]', '2026-08-22 10:00:00'),
            $this->message(1, 'nema', '2026-08-22 10:01:00'),
        ]);

        $next = (new LenaLoadQuestionnaire)->nextStep($draft, $messages, 99);

        $this->assertSame('bodyType', $next['key']);
    }

    public function test_a_side_question_does_not_consume_the_pending_step(): void
    {
        $messages = new Collection([
            $this->message(99, 'Special requirements? [[LENA_STEP:specialRequirements]]', '2026-08-22 10:00:00'),
            $this->message(1, 'Kako radi tracking?', '2026-08-22 10:01:00'),
        ]);

        $next = (new LenaLoadQuestionnaire)->nextStep($this->draftThroughCharacteristics(), $messages, 99);

        $this->assertSame('specialRequirements', $next['key']);
    }

    public function test_choose_later_skips_the_current_step_and_continues(): void
    {
        $messages = new Collection([
            $this->message(99, 'Special requirements? [[LENA_STEP:specialRequirements]]', '2026-08-22 10:00:00'),
            $this->message(1, '[[LENA_SKIP:specialRequirements]]', '2026-08-22 10:01:00'),
        ]);

        $next = (new LenaLoadQuestionnaire)->nextStep($this->draftThroughCharacteristics(), $messages, 99);

        $this->assertSame('pickup', $next['key']);
    }

    public function test_it_finishes_only_after_the_complete_scan_field_sequence(): void
    {
        $draft = $this->draftThroughCharacteristics() + [
            'specialRequirements' => ['Keep dry'],
            'pickupCity' => 'Sarajevo',
            'pickupDate' => '2026-08-23',
            'deliveryCity' => 'Berlin',
            'deliveryDate' => '2026-08-24',
            'budget' => 300,
            'currency' => 'EUR',
            'priceTerms' => 'fixed',
            'declaredValue' => 10000,
            'incoterm' => 'EXW',
            'temperatureMin' => 2,
            'temperatureMax' => 8,
            'requiresTracking' => true,
            'contactName' => 'Test Contact',
            'notes' => 'Call before pickup',
        ];

        $this->assertNull((new LenaLoadQuestionnaire)->nextStep($draft, collect(), 99));
    }

    public function test_only_the_complete_marker_counts_as_final_readiness(): void
    {
        $questionnaire = new LenaLoadQuestionnaire;

        $this->assertFalse($questionnaire->hasCompleteReadyMarker(collect([
            $this->message(99, '[[LOAD_READY_TO_POST]]', '2026-08-22 10:00:00'),
        ])));
        $this->assertTrue($questionnaire->hasCompleteReadyMarker(collect([
            $this->message(99, '[[LOAD_READY_TO_POST:complete]]', '2026-08-22 10:00:00'),
        ])));
    }

    private function draftThroughCharacteristics(): array
    {
        return [
            'title' => 'Steel',
            'transportType' => 'road',
            'goodsType' => 'Steel coils',
            'weightKg' => 12000,
            'pallets' => 10,
            'bodyType' => 'Curtain',
            'lengthM' => 6,
            'vehicleType' => 'Truck',
            'loadingEquipment' => 'Forklift: Yes',
            'characteristics' => 'CMR',
        ];
    }

    private function message(int $senderId, string $body, string $sentAt): Message
    {
        return new Message(['sender_user_id' => $senderId, 'body' => $body, 'sent_at' => $sentAt]);
    }
}
