<?php

namespace App\Services;

class LenaSkillCatalog
{
    public function rows(): array
    {
        $root = realpath(__DIR__.'/../../agents/lena');
        $paths = array_merge(glob($root.'/*/AGENT.md') ?: [], glob($root.'/*/skills/*.md') ?: [], [$root.'/hs/hs-detection.md']);
        sort($paths);
        $rows = [];
        foreach (array_unique($paths) as $path) {
            $resolved = realpath($path);
            if (! $resolved || ! str_starts_with($resolved, $root.DIRECTORY_SEPARATOR) || ! is_readable($resolved)) continue;
            $content = trim(file_get_contents($resolved));
            if ($content === '') continue;
            $relative = str_replace('\\', '/', substr($resolved, strlen($root) + 1));
            $folder = explode('/', $relative)[0];
            $shared = $relative === 'hs/hs-detection.md';
            $kind = basename($relative) === 'AGENT.md' ? 'instructions' : 'skill';
            preg_match('/^name:\s*(.+)$/m', $content, $name);
            preg_match('/^description:\s*(.+)$/m', $content, $description);
            preg_match('/^#\s+(.+)$/m', $content, $heading);
            $plain = preg_replace('/\A---\R.*?\R---\R/s', '', $content);
            $paragraphs = preg_split('/\R\s*\R/', trim($plain));
            $summary = $description[1] ?? current(array_filter($paragraphs, fn ($p) => ! str_starts_with($p, '#'))) ?: '';
            $rows[] = ['id' => $relative, 'name' => trim($name[1] ?? $heading[1] ?? $folder),
                'description' => trim($summary), 'folder' => $folder, 'kind' => $kind, 'shared' => $shared,
                'modes' => $shared ? ['general', 'legal', 'post-load', 'storage', 'tracking', 'booking', 'hs', 'free', 'about-load'] : [str_starts_with($folder, 'legal-') ? 'legal' : $folder],
                'scanners' => $shared, 'content' => $content, 'bytes' => filesize($resolved),
                'updatedAt' => gmdate('c', filemtime($resolved))];
        }
        return $rows;
    }
}
