<?php

namespace Tests\Feature\Api;

use App\Http\Controllers\Api\AircraftController;
use App\Http\Controllers\Api\VesselController;
use App\Models\Role;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ChecklistSearchAccessTest extends TestCase
{
    public static function roles(): array
    {
        return array_map(fn ($role) => [$role], [
            'user', 'driver', 'company', 'manager', 'dispatcher',
            'customs_officer', 'finance', 'warehouse', 'superadmin', 'master',
        ]);
    }

    #[DataProvider('roles')]
    public function test_every_role_can_search_aircraft_and_vessels(string $role): void
    {
        $user = new User();
        $user->id = 1;
        $user->setRelation('role', new Role(['name' => $role]));
        Sanctum::actingAs($user);

        foreach (['aircraft' => AircraftController::class, 'vessels' => VesselController::class] as $path => $controller) {
            $this->mock($controller, function ($mock): void {
                $mock->shouldReceive('index')->once()->andReturn(response()->json(['data' => []]));
            });

            $this->getJson('/api/'.$path.'?search=example')->assertOk()->assertJson(['data' => []]);
        }
    }

    public function test_guests_cannot_search_aircraft_or_vessels(): void
    {
        $this->getJson('/api/aircraft?search=example')->assertUnauthorized();
        $this->getJson('/api/vessels?search=example')->assertUnauthorized();
    }
}
