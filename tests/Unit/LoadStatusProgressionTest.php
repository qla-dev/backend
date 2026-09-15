<?php

namespace Tests\Unit;

use App\Services\LoadStatusProgression;
use PHPUnit\Framework\TestCase;

class LoadStatusProgressionTest extends TestCase
{
    public function test_every_earlier_stage_is_a_regression(): void
    {
        $stages = ['pending', 'posted', 'booked', 'opened', 'in_delivery', 'received', 'review', 'finished', 'cancelled'];
        foreach ($stages as $fromIndex => $from) {
            foreach ($stages as $toIndex => $to) {
                self::assertSame($toIndex < $fromIndex, LoadStatusProgression::isBackward($from, $to), "$from -> $to");
            }
        }
    }

    public function test_legacy_sent_is_equivalent_to_booked(): void
    {
        self::assertFalse(LoadStatusProgression::isBackward('sent', 'booked'));
        self::assertFalse(LoadStatusProgression::isBackward('booked', 'sent'));
        self::assertTrue(LoadStatusProgression::isBackward('received', 'sent'));
    }
}
