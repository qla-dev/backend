<?php

namespace App\Services;

use App\Models\Load;
use Illuminate\Validation\ValidationException;

class LoadStatusProgression
{
    public static function isBackward(string $from, string $to): bool
    {
        $rank = ['pending' => 0, 'posted' => 1, 'sent' => 2, 'booked' => 2, 'opened' => 3,
            'in_delivery' => 4, 'received' => 5, 'review' => 6, 'finished' => 7, 'cancelled' => 8];
        return isset($rank[$from], $rank[$to]) && $rank[$to] < $rank[$from];
    }

    public static function assertAllowed(Load $load): void
    {
        if ($load->exists && auth()->user()?->role?->name !== 'superadmin'
            && self::isBackward((string) $load->getRawOriginal('status'), $load->status)) {
            throw ValidationException::withMessages([
                'status' => 'You cannot move a load to an earlier status. Contact admin support and explain your valid reason for requesting this change.',
                'support_required' => 'status_regression',
            ]);
        }
    }
}
