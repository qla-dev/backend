<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Shipment;
use App\Services\TrackingNumberGenerator;
use Illuminate\Http\JsonResponse;

class PublicTrackingController extends Controller
{
    /**
     * Resolve an exact Freightbook tracking number without invoking LenaAI.
     * Only operational tracking fields safe for a public tracking page are returned.
     */
    public function show(string $trackingNumber): JsonResponse
    {
        $trackingNumber = strtoupper(trim($trackingNumber));

        if (! preg_match(TrackingNumberGenerator::FORMAT_REGEX, $trackingNumber)) {
            return $this->notFound();
        }

        $shipment = Shipment::query()
            ->with([
                'freightLoad:id,title,status,transport_type,cargo_type,goods_type,weight_kg,currency,budget',
                'freightLoad.stops' => fn ($query) => $query
                    ->select(['id', 'load_id', 'type', 'position', 'city', 'country_code'])
                    ->orderBy('position'),
                'events' => fn ($query) => $query
                    ->select(['id', 'shipment_id', 'status', 'title', 'location', 'occurred_at'])
                    ->orderByDesc('occurred_at')
                    ->limit(1),
            ])
            ->where('tracking_number', $trackingNumber)
            ->first();

        if (! $shipment || ! $shipment->freightLoad) {
            return $this->notFound();
        }

        $load = $shipment->freightLoad;
        $origin = $load->stops->firstWhere('type', 'pickup') ?? $load->stops->first();
        $destination = $load->stops->firstWhere('type', 'delivery') ?? $load->stops->last();
        $latestEvent = $shipment->events->first();

        return response()->json([
            'message' => 'Shipment tracking details retrieved.',
            'data' => [
                'tracking_number' => $shipment->tracking_number,
                'load_id' => (string) $load->id,
                'title' => $load->title,
                'status' => $shipment->status ?: $load->status,
                'transport_type' => $load->transport_type,
                'carrier' => $shipment->carrier,
                'origin' => $origin ? ['city' => $origin->city, 'country_code' => $origin->country_code] : null,
                'destination' => $destination ? ['city' => $destination->city, 'country_code' => $destination->country_code] : null,
                'estimated_delivery_at' => optional($shipment->estimated_delivery_at)->toIso8601String(),
                'latest_event' => $latestEvent ? [
                    'status' => $latestEvent->status,
                    'title' => $latestEvent->title,
                    'location' => $latestEvent->location,
                    'occurred_at' => optional($latestEvent->occurred_at)->toIso8601String(),
                ] : null,
                'load' => [
                    'id' => $load->id,
                    'title' => $load->title,
                    'status' => $load->status,
                    'transport_type' => $load->transport_type,
                    'cargo_type' => $load->cargo_type,
                    'goods_type' => $load->goods_type,
                    'weight_kg' => $load->weight_kg,
                    'currency' => $load->currency,
                    'budget' => $load->budget,
                    'booking_reference' => $shipment->tracking_number,
                    'stops' => $load->stops->map(fn ($stop): array => [
                        'type' => $stop->type,
                        'position' => $stop->position,
                        'city' => $stop->city,
                        'country_code' => $stop->country_code,
                    ])->values(),
                    'shipment' => [
                        'tracking_number' => $shipment->tracking_number,
                        'status' => $shipment->status,
                        'carrier' => $shipment->carrier,
                        'estimated_delivery_at' => optional($shipment->estimated_delivery_at)->toIso8601String(),
                        'events' => $latestEvent ? [[
                            'status' => $latestEvent->status,
                            'title' => $latestEvent->title,
                            'location' => $latestEvent->location,
                            'occurred_at' => optional($latestEvent->occurred_at)->toIso8601String(),
                        ]] : [],
                    ],
                ],
            ],
            'meta' => ['source' => 'shipment_database', 'ai_used' => false],
            'errors' => [],
        ]);
    }

    private function notFound(): JsonResponse
    {
        return response()->json([
            'message' => 'No shipment was found for that tracking number.',
            'data' => null,
            'meta' => ['source' => 'shipment_database', 'ai_used' => false],
            'errors' => [],
        ], 404);
    }
}
