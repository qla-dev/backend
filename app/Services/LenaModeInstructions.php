<?php

namespace App\Services;

/** Loads version-controlled, mode-specific LenaAI instructions from AGENT.md files. */
class LenaModeInstructions
{
    private const MODES = [
        'general', 'legal', 'post-load', 'storage', 'tracking', 'booking', 'hs', 'free', 'about-load',
    ];

    public function for(string $mode): string
    {
        $mode = in_array($mode, self::MODES, true) ? $mode : 'general';
        $path = base_path('agents/lena/'.$mode.'/AGENT.md');

        if (! is_file($path) || ! is_readable($path)) {
            return '';
        }

        $instructions = trim((string) file_get_contents($path));

        return $instructions === '' ? '' : "\n\nMode instructions ({$mode}):\n{$instructions}\n";
    }
}
