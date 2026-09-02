<?php

namespace App\Services\Contracts;

interface VesselStreamClient
{
    /** @return array<int, array<string, mixed>> */
    public function capture(float $south, float $west, float $north, float $east, float $seconds = 2.5): array;
}
