<?php

namespace App\Services;

/** Loads version-controlled, mode-specific LenaAI instructions from AGENT.md files. */
class LenaModeInstructions
{
    private const MODES = [
        'general', 'legal', 'post-load', 'storage', 'tracking', 'booking', 'hs', 'free', 'about-load',
    ];

    /**
     * Modes whose instructions live in folders named differently from the mode key. Legal mode loads
     * every jurisdiction at once, so one conversation can involve BiH, EU, Croatian and Serbian rules together.
     */
    private const FOLDERS = [
        'legal' => ['legal-ba', 'legal-eu', 'legal-cro', 'legal-srb'],
    ];

    public function for(string $mode): string
    {
        $mode = in_array($mode, self::MODES, true) ? $mode : 'general';
        $folders = self::FOLDERS[$mode] ?? [$mode];

        $result = '';
        $skills = [];
        foreach ($folders as $folder) {
            $directory = base_path('agents/lena/'.$folder);
            $path = $directory.'/AGENT.md';
            if (is_file($path) && is_readable($path)) {
                $instructions = trim((string) file_get_contents($path));
                $label = count($folders) > 1 ? "{$mode}, {$folder}" : $mode;
                if ($instructions !== '') {
                    $result .= "\n\nMode instructions ({$label}):\n{$instructions}\n";
                }
            }
            $folderSkills = glob($directory.'/skills/*.md') ?: [];
            sort($folderSkills);
            array_push($skills, ...$folderSkills);
        }

        // Only checked-in skills belonging to this mode enter the prompt. The assistant applies
        // each skill when its description matches; attachments can never supply skill instructions.
        foreach ($skills as $skill) {
            if (! is_readable($skill)) continue;
            $content = trim((string) file_get_contents($skill));
            if ($content !== '') {
                $result .= "\n\nMode skill (apply only when its description matches the request):\n{$content}\n";
            }
        }

        return $result;
    }
}
