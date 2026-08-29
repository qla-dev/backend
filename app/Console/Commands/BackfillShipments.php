<?php

namespace App\Console\Commands;

use App\Models\Load;
use App\Services\TrackingNumberGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillShipments extends Command
{
    protected $signature = 'shipments:backfill';

    protected $description = 'Create missing shipments, assign Freightbook tracking numbers, and add demo coordinates to non-posted loads';

    /** @var array<int, array{0: float, 1: float}> */
    private const EUROPE_COORDINATES = [
        [43.8563, 18.4131], // Sarajevo
        [45.8150, 15.9819], // Zagreb
        [46.0569, 14.5058], // Ljubljana
        [44.7866, 20.4489], // Belgrade
        [47.4979, 19.0402], // Budapest
        [48.2082, 16.3738], // Vienna
        [48.1351, 11.5820], // Munich
        [50.1109, 8.6821],  // Frankfurt
        [52.5200, 13.4050], // Berlin
        [50.0755, 14.4378], // Prague
        [48.1486, 17.1077], // Bratislava
        [52.2297, 21.0122], // Warsaw
        [45.4642, 9.1900],  // Milan
        [48.8566, 2.3522],  // Paris
        [50.8503, 4.3517],  // Brussels
        [51.9244, 4.4777],  // Rotterdam
    ];

    public function handle(TrackingNumberGenerator $trackingNumbers): int
    {
        $created = 0;
        $renumbered = 0;
        $located = 0;

        DB::transaction(function () use ($trackingNumbers, &$created, &$renumbered, &$located): void {
            $loads = Load::query()
                ->with('shipment')
                ->orderBy('id')
                ->get();

            $trackingQueues = $loads
                ->filter(fn (Load $load): bool => ! $load->shipment
                    || ! $trackingNumbers->isValid($load->shipment->tracking_number))
                ->groupBy('transport_type')
                ->map(fn ($group, string $transportType): array => $trackingNumbers->generateBatch($transportType, $group->count()))
                ->all();

            $loads->each(function (Load $load) use ($trackingNumbers, &$trackingQueues, &$created, &$renumbered, &$located): void {
                $shipment = $load->shipment;

                if (! $shipment) {
                    $shipment = $load->shipment()->create([
                        'tracking_number' => array_shift($trackingQueues[$load->transport_type]),
                    ]);
                    $created++;
                } elseif (! $trackingNumbers->isValid($shipment->tracking_number)) {
                    $shipment->tracking_number = array_shift($trackingQueues[$load->transport_type]);
                    $renumbered++;
                }

                if ($load->status !== 'posted'
                    && ($shipment->current_latitude === null || $shipment->current_longitude === null)) {
                    [$latitude, $longitude] = $this->randomEuropeanCoordinates();
                    $shipment->current_latitude = $latitude;
                    $shipment->current_longitude = $longitude;
                    $located++;
                }

                if ($shipment->isDirty()) {
                    $shipment->save();
                }
            });
        });

        $this->info("Shipments created: {$created}");
        $this->info("Legacy tracking numbers replaced: {$renumbered}");
        $this->info("Non-posted shipments located: {$located}");

        return self::SUCCESS;
    }

    /** @return array{0: float, 1: float} */
    private function randomEuropeanCoordinates(): array
    {
        [$latitude, $longitude] = self::EUROPE_COORDINATES[array_rand(self::EUROPE_COORDINATES)];

        return [
            round($latitude + random_int(-3000, 3000) / 10000, 7),
            round($longitude + random_int(-3000, 3000) / 10000, 7),
        ];
    }
}
