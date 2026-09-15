<?php

namespace Tests\Feature\Api;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VehicleLocationReporterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(':memory:', DB::connection()->getConfig('database'));

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
        });
        Schema::create('vehicles', function (Blueprint $table): void {
            $table->id();
        });
        Schema::create('vehicle_locations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('vehicle_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->decimal('speed_kph', 7, 2)->nullable();
            $table->decimal('heading', 6, 2)->nullable();
            $table->timestamp('recorded_at');
            $table->timestamps();
        });
        DB::table('users')->insert(['id' => 7, 'name' => 'Driver']);
        DB::table('vehicles')->insert(['id' => 1]);

        $user = new User();
        $user->id = 7;
        $user->setRelation('role', new Role(['name' => 'superadmin']));
        Sanctum::actingAs($user);
    }

    public function test_bulk_positions_record_the_reporting_user(): void
    {
        $this->postJson('/api/vehicle-locations/bulk', ['positions' => [
            ['vehicle_id' => 1, 'latitude' => 45, 'longitude' => 16, 'recorded_at' => '2026-09-15T10:00:00Z'],
            ['vehicle_id' => 1, 'latitude' => 45.1, 'longitude' => 16.1, 'recorded_at' => '2026-09-15T10:00:01Z'],
        ]])->assertCreated()->assertJsonPath('data.accepted', 2);

        $this->assertSame([7, 7], DB::table('vehicle_locations')->orderBy('id')->pluck('user_id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_single_position_records_the_caller_not_a_client_supplied_user(): void
    {
        $this->postJson('/api/vehicle-locations', [
            'vehicle_id' => 1, 'user_id' => 999, 'latitude' => 45, 'longitude' => 16, 'recorded_at' => '2026-09-15T10:00:00Z',
        ])->assertCreated();

        $this->assertSame(7, (int) DB::table('vehicle_locations')->value('user_id'));
    }
}
