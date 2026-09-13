<?php

namespace App\Services;

/** Loads version-controlled, mode-specific LenaAI instructions from AGENT.md files. */
class LenaModeInstructions
{
    /** Reused by conversation modes and scanners; the HS folder owns these instructions. */
    public function hsDetection(): string
    {
        return "\n\nHS detection skill (apply only when relevant; follow the supplied response schema):\n".self::split((string) file_get_contents(__DIR__.'/../../agents/lena/hs/hs-detection.md'))['prompt']."\n";
    }
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

    /**
     * An instruction file is its prompt followed by an optional closing "## Sources" section that lists
     * legal-sources.json ids, one "- id" per line. The section is for the skills screen: legal mode already
     * supplies the full catalogue, so it never enters the prompt.
     *
     * @return array{prompt: string, sources: list<string>}
     */
    public static function split(string $content): array
    {
        $parts = preg_split('/^##\s+Sources\s*$/mi', $content, 2);
        preg_match_all('/^\s*-\s*([a-z0-9-]+)\s*$/m', $parts[1] ?? '', $ids);

        return ['prompt' => trim($parts[0]), 'sources' => array_values(array_unique($ids[1]))];
    }

    public function for(string $mode): string
    {
        $mode = in_array($mode, self::MODES, true) ? $mode : 'general';
        $folders = self::FOLDERS[$mode] ?? [$mode];

        $result = $this->hsDetection();
        $skills = [];
        foreach ($folders as $folder) {
            $directory = base_path('agents/lena/'.$folder);
            $path = $directory.'/AGENT.md';
            if (is_file($path) && is_readable($path)) {
                $instructions = self::split((string) file_get_contents($path))['prompt'];
                $label = count($folders) > 1 ? "{$mode}, {$folder}" : $mode;
                if ($instructions !== '') {
                    $result .= "\n\nMode instructions ({$label}):\n{$instructions}\n";
                }
            }
            $folderSkills = glob($directory.'/skills/*.md') ?: [];
            sort($folderSkills);
            array_push($skills, ...$folderSkills);
        }

        // In addition to shared skills, only checked-in skills belonging to this mode enter the prompt. The assistant applies
        // each skill when its description matches; attachments can never supply skill instructions.
        foreach ($skills as $skill) {
            if (! is_readable($skill)) continue;
            $content = self::split((string) file_get_contents($skill))['prompt'];
            if ($content !== '') {
                $result .= "\n\nMode skill (apply only when its description matches the request):\n{$content}\n";
            }
        }

        return $result;
    }
}
