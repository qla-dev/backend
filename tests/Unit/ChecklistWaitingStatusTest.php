<?php

namespace Tests\Unit;

use App\Models\Load;
use App\Services\ChecklistStatusRequirements;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\TestCase;

class ChecklistWaitingStatusTest extends TestCase
{
    public function test_pod_spans_delivery_and_receipt_and_other_tasks_have_no_prerequisite(): void
    {
        // Only validation services are installed: no application or database is booted.
        $previous = Facade::getFacadeApplication();
        $container = new Container;
        $container->instance('validator', new Factory(new Translator(new ArrayLoader, 'en')));
        Facade::setFacadeApplication($container);
        try {
            // The driver marks the load received on arrival and files the POD afterwards, so the
            // upload window covers both statuses.
            $open = ['in_delivery', 'received'];
            foreach (['posted', 'booked', 'sent', 'in_delivery', 'received', 'review', 'finished', 'cancelled'] as $status) {
                $load = (new Load)->setRawAttributes(['status' => $status]);
                ChecklistStatusRequirements::assertTaskAllowed($load, ['key' => 'cmr_and_documents']);
                try {
                    ChecklistStatusRequirements::assertTaskAllowed($load, ['key' => 'proof_of_delivery']);
                    $this->assertContains($status, $open);
                } catch (ValidationException $exception) {
                    $this->assertNotContains($status, $open);
                    $this->assertArrayHasKey('waiting_for_status', $exception->errors());
                }
            }
            $items = ChecklistStatusRequirements::withCategories([
                ['key' => 'proof_of_delivery', 'waiting_for_status' => null],
                ['key' => 'vehicle_return', 'waiting_for_status' => 'booked'],
            ]);
            $this->assertSame('in_delivery', $items[0]['waiting_for_status']);
            $this->assertSame('review', $items[0]['required_for_status']);
            $this->assertNull($items[1]['waiting_for_status']);
            $this->assertSame('booked', (new Load)->setRawAttributes(['status' => 'sent'])->status);
            $this->assertNotContains('sent', Load::STATUSES);
        } finally {
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($previous);
        }
    }
}
