<?php

namespace App\Services;

/** Loads version-controlled, mode-specific LenaAI instructions from AGENT.md files. */
class LenaModeInstructions
{
    /** Skills shared by every mode. The document and text scanners also reuse hs-detection on its own. */
    private const SHARED_SKILLS = __DIR__.'/../../agents/lena/skills';

    public function hsDetection(): string
    {
        return $this->sharedSkill(self::SHARED_SKILLS.'/hs-detection.md');
    }

    /** The shared skills overview (agents/lena/skills/AGENT.md), then every other skill there in file-name order. */
    public function shared(): string
    {
        $overviewPath = self::SHARED_SKILLS.'/AGENT.md';
        $overview = is_readable($overviewPath) ? self::split((string) file_get_contents($overviewPath))['prompt'] : '';
        $paths = array_filter(glob(self::SHARED_SKILLS.'/*.md') ?: [], fn (string $path) => basename($path) !== 'AGENT.md');
        sort($paths);

        return ($overview === '' ? '' : "\n\nShared skills (every mode):\n{$overview}\n")
            .implode('', array_map(fn (string $path) => $this->sharedSkill($path), $paths));
    }

    private function sharedSkill(string $path): string
    {
        $content = is_readable($path) ? self::split((string) file_get_contents($path))['prompt'] : '';

        return $content === '' ? '' : "\n\nShared skill (apply only when relevant; follow any supplied response schema):\n{$content}\n";
    }
    private const MODES = [
        'general', 'legal', 'post-load', 'storage', 'tracking', 'booking', 'hs', 'free', 'about-load',
    ];

    /**
     * Modes whose instructions live in more than one folder. Legal mode loads its overview in
     * agents/lena/legal first and then every jurisdiction at once, so one conversation can involve BiH,
     * EU, Croatian and Serbian rules together.
     */
    private const FOLDERS = [
        'legal' => ['legal', 'legal/legal-ba', 'legal/legal-eu', 'legal/legal-cro', 'legal/legal-srb'],
    ];

    /**
     * An instruction file is its prompt followed by an optional closing "## Name" section for the skills screen, one
     * "- bs: ...", "- en: ...", "- de: ..." line each, which never enters the prompt. Resources are kept apart from the
     * prompts, in each folder's resources/resources.json (see LenaSkillResources).
     *
     * @return array{prompt: string, names: array<string, string>}
     */
    public static function split(string $content): array
    {
        $parts = preg_split('/^##\s+Name\s*$/mi', $content, 2);
        preg_match_all('/^\s*-\s*(bs|en|de|hr|sr)\s*:\s*(\S.*?)\s*$/m', $parts[1] ?? '', $names, PREG_SET_ORDER);

        return ['prompt' => trim($parts[0]), 'names' => array_column($names, 2, 1)];
    }

    public function for(string $mode): string
    {
        $mode = in_array($mode, self::MODES, true) ? $mode : 'general';
        $folders = self::FOLDERS[$mode] ?? [$mode];

        $result = $this->shared();
        $skills = [];
        foreach ($folders as $folder) {
            $path = base_path('agents/lena/'.$folder.'/AGENT.md');
            if (is_file($path) && is_readable($path)) {
                $instructions = self::split((string) file_get_contents($path))['prompt'];
                $label = count($folders) > 1 && $folder !== $mode ? "{$mode}, ".basename($folder) : $mode;
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
