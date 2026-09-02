<?php

namespace App\Services;

use App\Services\Contracts\VesselStreamClient;
use RuntimeException;
use Symfony\Component\Process\Process;

class AisVesselStreamClient implements VesselStreamClient
{
    public function capture(float $south, float $west, float $north, float $east, float $seconds = 2.5): array
    {
        $apiKey = trim((string) config('services.vessel_stream.api_key'));
        if ($apiKey === '') throw new RuntimeException('Vessel live-data API key is not configured.');

        $process = new Process([
            (string) config('services.vessel_stream.node_binary', 'node'),
            base_path('scripts/vessel_stream_collector.mjs'),
            (string) $south, (string) $west, (string) $north, (string) $east,
            (string) max(0.5, min(8.0, $seconds)),
        ], base_path(), ['AISSTREAM_API_KEY' => $apiKey, 'AISSTREAM_URL' => (string) config('services.vessel_stream.url')]);
        $process->setTimeout(15)->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException(trim($process->getErrorOutput()) ?: 'The vessel live-data stream failed.');
        }
        $rows = json_decode($process->getOutput(), true);
        if (! is_array($rows)) throw new RuntimeException('The vessel live-data response was invalid.');
        return $rows;
    }
}
