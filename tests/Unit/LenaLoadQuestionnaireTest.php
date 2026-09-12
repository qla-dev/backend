<?php

namespace Tests\Unit;

use App\Models\Message;
use App\Services\LenaCatalog;
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

    public function test_each_transport_type_is_asked_only_its_own_steps(): void
    {
        $questionnaire = new LenaLoadQuestionnaire;
        $asked = [];
        foreach (LenaCatalog::TRANSPORTS as $transport) {
            $asked[$transport] = $this->stepsAskedFor($questionnaire, $transport);
        }

        // A truck is never asked for containers or a Bill of Lading; a storage request is never
        // asked about a vehicle or a route price term.
        $this->assertContains('bodyType', $asked['road']);
        $this->assertContains('extraStops', $asked['road']);
        $this->assertNotContains('containers', $asked['road']);
        $this->assertNotContains('documentType', $asked['road']);
        $this->assertContains('transportMode', $asked['air']);
        $this->assertNotContains('bodyType', $asked['air']);
        foreach (['sea', 'rail'] as $containerTransport) {
            $this->assertContains('containers', $asked[$containerTransport]);
            $this->assertContains('outOfGauge', $asked[$containerTransport]);
            $this->assertContains('transitDays', $asked[$containerTransport]);
            $this->assertContains('documentType', $asked[$containerTransport]);
        }
        foreach (['storageType', 'storagePeriod', 'storageTemperature', 'storageServices', 'storageRate'] as $storageStep) {
            $this->assertContains($storageStep, $asked['warehouse']);
            $this->assertNotContains($storageStep, $asked['road']);
        }
        $this->assertNotContains('vehicleType', $asked['warehouse']);
        $this->assertNotContains('priceTerms', $asked['warehouse']);
    }

    public function test_every_post_a_load_field_belongs_to_a_step_of_its_transport_type(): void
    {
        $catalog = app(LenaCatalog::class);
        $schema = $catalog->schema();

        foreach (LenaCatalog::TRANSPORTS as $transport) {
            $covered = [];
            foreach ($catalog->steps($transport) as $step) {
                $covered = [...$covered, ...$catalog->stepFields($step, $transport)];
            }
            foreach ($schema['form_fields'] as $field => $definition) {
                $step = $schema['steps'][$definition['step']];
                $this->assertSame(
                    in_array($transport, $step['transports'], true) && in_array($field, $catalog->stepFields($step, $transport), true),
                    in_array($field, $covered, true),
                    "$field is not asked for consistently on $transport"
                );
            }
        }
    }

    public function test_it_follows_scan_field_order_and_accepts_explicit_none(): void
    {
        $draft = $this->draftThroughGoods();
        $messages = new Collection([
            $this->message(99, 'How many pallets? [[LENA_STEP:pallets]]', '2026-08-22 10:00:00'),
            $this->message(1, 'nema', '2026-08-22 10:01:00'),
        ]);

        $next = (new LenaLoadQuestionnaire)->nextStep($draft, $messages, 99);

        $this->assertSame('dimensions', $next['key']);
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
            'extraPickups' => [['city' => 'Graz']],
            'budget' => 300,
            'currency' => 'EUR',
            'priceTerms' => 'fixed',
            'declaredValue' => 10000,
            'incoterm' => 'EXW',
            'paymentDueDays' => 30,
            'requiresTracking' => true,
            'contactName' => 'Test Contact',
            'supplierName' => 'Steel Mill d.o.o.',
            'closedFreightExchange' => 'TIMOCOM',
            'internalComments' => 'Regular customer',
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

    // Walks a transport type's whole questionnaire by skipping every step it is offered, so the
    // list is what a user of that transport type is actually asked.
    private function stepsAskedFor(LenaLoadQuestionnaire $questionnaire, string $transport): array
    {
        $asked = [];
        $messages = new Collection;
        $minute = 0;
        while ($next = $questionnaire->nextStep(['transportType' => $transport, 'storageTarget' => 'own', 'warehouseId' => 7], $messages, 99)) {
            if (in_array($next['key'], $asked, true)) {
                $this->fail("{$next['key']} was asked twice on $transport");
            }
            $asked[] = $next['key'];
            $messages->push($this->message(1, "[[LENA_SKIP:{$next['key']}]]", '2026-08-22 10:'.sprintf('%02d', $minute++).':00'));
        }

        return $asked;
    }

    private function draftThroughGoods(): array
    {
        return [
            'title' => 'Steel',
            'transportType' => 'road',
            'consigneeName' => 'Delta Trade d.o.o.',
            'cargoType' => 'FTL',
            'goodsType' => 'Steel coils',
            'hsCodes' => [['code' => '7208.10']],
            'quantityMeasure' => 'PX',
            'weightKg' => 12000,
        ];
    }

    private function draftThroughCharacteristics(): array
    {
        return $this->draftThroughGoods() + [
            'pallets' => 10,
            'lengthM' => 6,
            'bodyType' => 'Curtain',
            'vehicleType' => 'Truck',
            'loadingEquipment' => 'Forklift: Yes',
            'characteristics' => 'CMR',
            'dgUnNumber' => 'UN 1263',
            'temperatureMin' => 2,
            'temperatureMax' => 8,
        ];
    }

    private function message(int $senderId, string $body, string $sentAt): Message
    {
        return new Message(['sender_user_id' => $senderId, 'body' => $body, 'sent_at' => $sentAt]);
    }
}
