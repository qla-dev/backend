<?php

namespace App\Services\Contracts;

interface VesselSnapshotClient
{
    /** @return array<int, array<string, mixed>> */
    public function capture(
        float $south,
        float $west,
        float $north,
        float $east,
        array $mmsis = [],
    ): array;
}
