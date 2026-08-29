<?php

namespace Tests\Feature;

use App\Models\FuelStation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ImportFueloFuelStationsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('fuel_stations', function (Blueprint $table): void {
            $table->id();
            $table->string('source', 30)->default('fuelo');
            $table->string('source_type', 20)->default('station');
            $table->string('source_id', 100);
            $table->string('name')->nullable();
            $table->string('brand')->nullable();
            $table->string('operator')->nullable();
            $table->string('address')->nullable();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->string('opening_hours')->nullable();
            $table->boolean('hgv')->nullable();
            $table->json('fuel_types')->nullable();
            $table->json('tags')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamp('source_updated_at')->nullable();
            $table->timestamp('last_synced_at');
            $table->timestamps();
            $table->unique(['source', 'source_type', 'source_id']);
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('fuel_stations');
        parent::tearDown();
    }

    public function test_saved_fuelo_payload_is_normalized_and_upserted(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'fuelo-test-');
        file_put_contents($path, json_encode([
            [
                'id' => 'de-001-A',
                'title' => 'Autohof Nord',
                'brand_name' => 'Example Fuel',
                'lat' => 52.520008,
                'lon' => 13.404954,
                'address' => 'Teststrasse 1, Berlin',
            ],
            ['cluster_count' => 12, 'avglat' => 52.5, 'avglon' => 13.4],
        ], JSON_THROW_ON_ERROR));

        try {
            $this->artisan('fuel-stations:import-fuelo', [
                'south' => 52.4,
                'west' => 13.3,
                'north' => 52.6,
                'east' => 13.5,
                '--input' => $path,
            ])->assertSuccessful();
        } finally {
            unlink($path);
        }

        $this->assertDatabaseCount('fuel_stations', 1);
        $station = FuelStation::query()->firstOrFail();
        $this->assertSame('fuelo', $station->source);
        $this->assertSame('station', $station->source_type);
        $this->assertSame('de-001-A', $station->source_id);
        $this->assertSame('Autohof Nord', $station->name);
        $this->assertSame('Example Fuel', $station->brand);
        $this->assertSame('Teststrasse 1, Berlin', $station->address);
        $this->assertSame(13.404954, $station->longitude);
        $this->assertSame('de-001-A', $station->raw_payload['id']);
    }
}
