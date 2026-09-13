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
     * every jurisdiction under agents/lena/legal at once, so one conversation can involve BiH, EU, Croatian
     * and Serbian rules together.
     */
    private const FOLDERS = [
        'legal' => ['legal/legal-ba', 'legal/legal-eu', 'legal/legal-cro', 'legal/legal-srb'],
    ];

    /**
     * An instruction file is its prompt followed by an optional closing "## Sources" section, one resource per line:
     *   - legal-source-id            a document from legal-sources.json
     *   - [Title](https://...)       a web page
     *   - [Title](app:view-id)       a screen of this app, such as the internal tariff catalogue
     * The section is for the skills screen and never enters the prompt: legal mode already supplies the full catalogue.
     *
     * @return array{prompt: string, sources: list<array{type: 'legal', id: string}|array{type: 'link', title: string, url: string}|array{type: 'app', title: string, view: string}>}
     */
    public static function split(string $content): array
    {
        $parts = preg_split('/^##\s+Sources\s*$/mi', $content, 2);
        $sources = [];
        foreach (preg_split('/\R/', $parts[1] ?? '') as $line) {
            if (preg_match('/^\s*-\s*\[([^\]]+)\]\((?:app:([a-z0-9-]+)|(https:\/\/\S+))\)\s*$/', $line, $link)) {
                $sources[] = ($link[2] ?? '') !== ''
                    ? ['type' => 'app', 'title' => trim($link[1]), 'view' => $link[2]]
                    : ['type' => 'link', 'title' => trim($link[1]), 'url' => $link[3]];
            } elseif (preg_match('/^\s*-\s*([a-z0-9-]+)\s*$/', $line, $id)) {
                $sources[] = ['type' => 'legal', 'id' => $id[1]];
            }
        }

        return ['prompt' => trim($parts[0]), 'sources' => $sources];
    }

    public function for(string $mode): string
    {
        $mode = in_array($mode, self::MODES, true) ? $mode : 'general';
        $folders = self::FOLDERS[$mode] ?? [$mode];

        $result = $this->hsDetection();
        $skills = [];
        foreach ($folders as $folder) {
            $path = base_path('agents/lena/'.$folder.'/AGENT.md');
            if (is_file($path) && is_readable($path)) {
                $instructions = self::split((string) file_get_contents($path))['prompt'];
                $label = count($folders) > 1 ? "{$mode}, ".basename($folder) : $mode;
                if ($instructions !== '') {
                    $result .= "\n\nMode instructions ({$label}):\n{$instructions}\n";
                }
            }
        }
        // The mode's own folder holds skills shared by all its instruction folders, such as legal/skills.
        foreach (array_unique([...$folders, $mode]) as $folder) {
            $folderSkills = glob(base_path('agents/lena/'.$folder).'/skills/*.md') ?: [];
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
