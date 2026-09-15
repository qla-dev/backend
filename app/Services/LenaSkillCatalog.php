<?php

namespace App\Services;

class LenaSkillCatalog
{
    public function rows(): array
    {
        $root = realpath(__DIR__.'/../../agents/lena');
        // Modes sit at agents/lena/{mode}; legal groups its jurisdictions one level deeper, at legal/{jurisdiction}.
        $paths = array_merge(
            glob($root.'/*/AGENT.md') ?: [], glob($root.'/*/*/AGENT.md') ?: [],
            glob($root.'/*/skills/*.md') ?: [], glob($root.'/*/*/skills/*.md') ?: [],
            glob($root.'/skills/*.md') ?: [],
        );
        sort($paths);
        $resources = app(LenaSkillResources::class);
        $rows = [];
        foreach (array_unique($paths) as $path) {
            $resolved = realpath($path);
            if (! $resolved || ! str_starts_with($resolved, $root.DIRECTORY_SEPARATOR) || ! is_readable($resolved)) continue;
            ['prompt' => $content, 'names' => $names] = LenaModeInstructions::split((string) file_get_contents($resolved));
            if ($content === '') continue;
            $relative = str_replace('\\', '/', substr($resolved, strlen($root) + 1));
            // The folder an item belongs to: an AGENT.md's own directory, and for skills/*.md the directory above.
            $folder = preg_replace('#/skills$#', '', dirname($relative));
            // agents/lena/skills holds the skills every mode loads.
            $shared = dirname($relative) === 'skills';
            // instructions: a skill (an AGENT.md, or a shared skill, which belongs to no single mode); skill: a subskill of a mode.
            $kind = basename($relative) === 'AGENT.md' || $shared ? 'instructions' : 'skill';
            preg_match('/^name:\s*(.+)$/m', $content, $name);
            preg_match('/^description:\s*(.+)$/m', $content, $description);
            preg_match('/^#\s+(.+)$/m', $content, $heading);
            $plain = preg_replace('/\A---\R.*?\R---\R/s', '', $content);
            $paragraphs = preg_split('/\R\s*\R/', trim($plain));
            $summary = $description[1] ?? current(array_filter($paragraphs, fn ($p) => ! str_starts_with($p, '#'))) ?: '';
            // The display name comes from the file's "## Name" section; the slug is only a fallback.
            $rows[] = ['id' => $relative, 'name' => $names['en'] ?? trim($name[1] ?? $heading[1] ?? $folder), 'names' => $names,
                'description' => trim($summary), 'folder' => $folder, 'kind' => $kind, 'shared' => $shared,
                'modes' => $shared ? ['general', 'legal', 'post-load', 'storage', 'tracking', 'booking', 'hs', 'free', 'about-load', 'training'] : [explode('/', $folder)[0]],
                'scanners' => $relative === 'skills/hs-detection.md', 'content' => $content,
                // Kept in the folder's resources/resources.json, keyed by the file's path inside the folder.
                'sources' => $resources->for($folder, substr($relative, strlen($folder) + 1)),
                'bytes' => filesize($resolved),
                'updatedAt' => gmdate('c', filemtime($resolved))];
        }
        return $rows;
    }
}
