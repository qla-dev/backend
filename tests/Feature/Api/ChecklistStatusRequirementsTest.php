<?php

namespace Tests\Feature\Api;

use App\Models\Load;
use App\Models\ShipmentWorkspace;
use App\Services\ChecklistStatusRequirements;
use App\Services\ShipmentWorkspaceCreator;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ChecklistStatusRequirementsTest extends TestCase
{
    public static function modes(): array
    {
        return [['road'], ['air'], ['sea'], ['rail'], ['warehouse']];
    }

    private function items(string $mode): array
    {
        return (new \ReflectionMethod(ShipmentWorkspaceCreator::class, 'checklist'))
            ->invoke(new ShipmentWorkspaceCreator(), $mode);
    }

    private function loadWith(array $items, string $status, string $mode = 'road'): Load
    {
        $workspace = new ShipmentWorkspace([
            'load_snapshot' => ['transport_type' => $mode],
            'operational_checklist' => $items,
        ]);
        $load = new Load(['status' => $status, 'transport_type' => $mode]);
        $load->setRelation('shipmentWorkspace', $workspace);

        return $load;
    }

    #[DataProvider('modes')]
    public function test_pending_checklist_blocks_delivery_for_every_transport(string $mode): void
    {
        $this->expectException(ValidationException::class);
        app(ChecklistStatusRequirements::class)->assertAllowed($this->loadWith($this->items($mode), 'in_delivery', $mode));
    }

    #[DataProvider('modes')]
    public function test_completed_delivery_items_allow_departure_with_arrival_items_pending(string $mode): void
    {
        $items = array_map(fn ($item) => array_merge($item, [
            'status' => $item['required_for_status'] === 'in_delivery' ? 'completed' : 'pending',
        ]), $this->items($mode));
        app(ChecklistStatusRequirements::class)->assertAllowed($this->loadWith($items, 'in_delivery', $mode));
        $this->addToAssertionCount(1);
    }

    public function test_legacy_items_get_defaults_and_saved_categories_override_them(): void
    {
        $load = $this->loadWith([
            ['key' => 'assign_driver_and_vehicle', 'status' => 'pending'],
            ['key' => 'proof_of_delivery', 'status' => 'pending'],
            ['key' => 'vehicle_registrations', 'status' => 'pending', 'required_for_status' => 'received'],
        ], 'booked');
        $this->assertSame(['in_delivery', 'received', 'received'], array_column($load->shipmentWorkspace->operational_checklist, 'required_for_status'));
        $this->assertSame('received', ChecklistStatusRequirements::category(['key' => 'arrival_and_release_documents']));
    }

    public function test_moving_an_item_between_categories_changes_the_gate(): void
    {
        $item = ['key' => 'container_details', 'status' => 'pending', 'required_for_status' => 'received'];
        app(ChecklistStatusRequirements::class)->assertAllowed($this->loadWith([$item], 'in_delivery', 'sea'));
        $item['required_for_status'] = 'in_delivery';
        $this->expectException(ValidationException::class);
        app(ChecklistStatusRequirements::class)->assertAllowed($this->loadWith([$item], 'in_delivery', 'sea'));
    }

    public function test_receiving_requires_proof_of_delivery(): void
    {
        $this->expectException(ValidationException::class);
        app(ChecklistStatusRequirements::class)->assertAllowed($this->loadWith([
            ['key' => 'proof_of_delivery', 'status' => 'pending'],
        ], 'received'));
    }

    public function test_receiving_requires_arrival_documents(): void
    {
        $this->expectException(ValidationException::class);
        app(ChecklistStatusRequirements::class)->assertAllowed($this->loadWith([
            ['key' => 'arrival_and_release_documents', 'status' => 'blocked'],
        ], 'received', 'rail'));
    }

    public function test_completed_checklist_allows_receiving(): void
    {
        $items = array_map(fn ($item) => array_merge($item, ['status' => 'completed']), $this->items('road'));
        app(ChecklistStatusRequirements::class)->assertAllowed($this->loadWith($items, 'received'));
        $this->addToAssertionCount(1);
    }

    public function test_empty_checklist_cannot_bypass_the_gate(): void
    {
        $this->expectException(ValidationException::class);
        app(ChecklistStatusRequirements::class)->assertAllowed($this->loadWith([], 'in_delivery'));
    }

    public function test_model_save_enforces_the_gate_before_any_database_write(): void
    {
        $load = $this->loadWith($this->items('road'), 'booked');
        $load->exists = true;
        $load->syncOriginal();
        $load->status = 'in_delivery';
        $this->expectException(ValidationException::class);
        $load->save();
    }

    public function test_creation_cannot_bypass_the_gate(): void
    {
        $load = $this->loadWith([], 'in_delivery');
        $this->expectException(ValidationException::class);
        $load->save();
    }

    #[DataProvider('modes')]
    public function test_sent_requires_the_same_departure_items_for_every_mode(string $mode): void
    {
        $this->expectException(ValidationException::class);
        app(ChecklistStatusRequirements::class)->assertAllowed($this->loadWith($this->items($mode), 'sent', $mode));
    }

    #[DataProvider('modes')]
    public function test_sent_allows_pending_receipt_documents(string $mode): void
    {
        $items = array_map(fn ($item) => array_merge($item, [
            'status' => $item['required_for_status'] === 'in_delivery' ? 'completed' : 'pending',
        ]), $this->items($mode));
        app(ChecklistStatusRequirements::class)->assertAllowed($this->loadWith($items, 'sent', $mode));
        $this->addToAssertionCount(1);
    }
}
