<?php

namespace Tests\Feature\Api;

use App\Models\Load;
use App\Models\Role;
use App\Models\ShipmentWorkspace;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ChecklistPersistenceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $connection = DB::connection();
        $this->assertSame('sqlite', $connection->getConfig('driver'));
        $this->assertSame(':memory:', $connection->getConfig('database'));
        $this->assertEmpty($connection->getConfig('host'));
        $this->assertEmpty($connection->getConfig('port'));

        Schema::create('loads', function (Blueprint $table): void {
            $table->id();
            $table->string('status');
            $table->string('transport_type');
            $table->string('booking_status')->nullable();
            $table->json('status_change')->nullable();
            $table->timestamps();
        });
        Schema::create('shipment_workspace', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('load_id');
            $table->json('load_snapshot');
            $table->json('operational_checklist');
            $table->timestamps();
        });
        DB::table('loads')->insert(['id' => 1, 'status' => 'booked', 'transport_type' => 'road']);
        ShipmentWorkspace::create([
            'load_id' => 1,
            'load_snapshot' => ['transport_type' => 'road'],
            'operational_checklist' => [['key' => 'vehicle_registrations', 'status' => 'pending']],
        ]);
        $user = new User();
        $user->id = 1;
        $user->setRelation('role', new Role(['name' => 'superadmin']));
        Sanctum::actingAs($user);
    }

    public function test_category_survives_save_reload_and_controls_the_transition(): void
    {
        $workspace = ShipmentWorkspace::firstOrFail();
        $items = $workspace->operational_checklist;
        $items[0]['required_for_status'] = 'received';
        $workspace->update(['operational_checklist' => $items]);
        $this->assertSame('received', $workspace->fresh()->operational_checklist[0]['required_for_status']);

        $load = Load::findOrFail(1);
        $load->update(['status' => 'in_delivery']);
        $this->assertSame('in_delivery', $load->fresh()->status);
        $this->assertSame('in_execution', $load->fresh()->booking_status);
    }

    public function test_status_endpoint_rejects_even_admin_and_keeps_the_status(): void
    {
        $this->patchJson('/api/loads/1/status', ['status' => 'in_delivery'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertSame('booked', Load::findOrFail(1)->status);
    }

    public function test_general_update_endpoint_cannot_bypass_the_checklist(): void
    {
        $this->putJson('/api/loads/1', ['status' => 'in_delivery'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertSame('booked', Load::findOrFail(1)->status);
    }

    public function test_vehicle_tracking_uses_observation_time_instead_of_insertion_order(): void
    {
        Schema::create('vehicles', function (Blueprint $table): void {
            $table->id();
        });
        Schema::create('vehicle_locations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('vehicle_id');
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->timestamp('recorded_at');
        });
        DB::table('vehicles')->insert(['id' => 1]);
        DB::table('vehicle_locations')->insert([
            ['vehicle_id' => 1, 'latitude' => 45, 'longitude' => 16, 'recorded_at' => '2026-09-07 12:00:00'],
            ['vehicle_id' => 1, 'latitude' => 44, 'longitude' => 17, 'recorded_at' => '2026-09-07 11:00:00'],
        ]);
        $vehicle = \App\Models\Vehicle::with('latestLocation')->findOrFail(1);
        $this->assertSame('45.0000000', $vehicle->latestLocation->latitude);
    }
}
