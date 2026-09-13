<?php

namespace App\Services;

/**
 * Resources shown in the Resursi tab of the LenaAI skills screen. Every folder with an AGENT.md keeps them apart from
 * its prompts in resources/resources.json, keyed by file inside that folder (AGENT.md, skills/*.md). Resources never
 * enter the prompt.
 */
class LenaSkillResources
{
    public const FILE = 'resources/resources.json';

    /** Backend folders whose JSON data files a "file" resource may point to. */
    public const FILE_ROOTS = ['resources/lena/', 'agents/lena/'];

    public function __construct(private LegalSourceCatalog $legalSources) {}

    public static function path(string $folder): string
    {
        return base_path('agents/lena/'.$folder.'/'.self::FILE);
    }

    /** A backend JSON file inside one of the FILE_ROOTS, given relative to the backend root. */
    public static function isAllowedFile(string $path): bool
    {
        if (! str_ends_with($path, '.json') || str_contains($path, '..') || str_contains($path, '\\')) return false;

        return collect(self::FILE_ROOTS)->contains(fn (string $root) => str_starts_with($path, $root)) && is_file(base_path($path));
    }

    /**
     * One file's resources with legal ids resolved against legal-sources.json. Malformed entries and unknown ids are
     * dropped here and reported by LenaSkillCatalogTest.
     */
    public function for(string $folder, string $file): array
    {
        $entries = $this->entries($folder)[$file] ?? [];

        return array_values(array_filter(array_map(fn ($entry) => is_array($entry) ? $this->resolve($entry) : null, is_array($entries) ? $entries : [])));
    }

    /** The folder's map of file => raw entries. */
    public function entries(string $folder): array
    {
        $path = self::path($folder);
        if (! is_readable($path)) return [];
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return is_array($data['resources'] ?? null) ? $data['resources'] : [];
    }

    /** Every data file some resources.json points to: the only files the skills screen may open. */
    public function referencedFiles(): array
    {
        $root = base_path('agents/lena');
        $jsonFiles = array_merge(glob($root.'/*/'.self::FILE) ?: [], glob($root.'/*/*/'.self::FILE) ?: []);
        $files = [];
        foreach ($jsonFiles as $json) {
            $folder = str_replace('\\', '/', substr(dirname($json, 2), strlen($root) + 1));
            foreach ($this->entries($folder) as $entries) {
                foreach (is_array($entries) ? $entries : [] as $entry) {
                    $path = is_array($entry) && ($entry['type'] ?? null) === 'file' ? (string) ($entry['path'] ?? '') : '';
                    if ($path !== '' && self::isAllowedFile($path)) $files[] = $path;
                }
            }
        }

        return array_values(array_unique($files));
    }

    private function resolve(array $entry): ?array
    {
        $title = trim((string) ($entry['title'] ?? ''));

        switch ($entry['type'] ?? null) {
            case 'legal':
                $source = $this->legalSources->find((string) ($entry['id'] ?? ''));

                return $source ? ['type' => 'legal', 'id' => $source['id'], 'title' => $source['title'], 'file' => $source['file'], 'jurisdiction' => $source['jurisdiction']] : null;
            case 'link':
                return $title !== '' && str_starts_with((string) ($entry['url'] ?? ''), 'https://') ? ['type' => 'link', 'title' => $title, 'url' => $entry['url']] : null;
            case 'app':
                return $title !== '' && preg_match('/^[a-z0-9-]+$/', (string) ($entry['view'] ?? '')) === 1 ? ['type' => 'app', 'title' => $title, 'view' => $entry['view']] : null;
            case 'file':
                $path = (string) ($entry['path'] ?? '');

                return $title !== '' && self::isAllowedFile($path) ? ['type' => 'file', 'title' => $title, 'path' => $path, 'bytes' => filesize(base_path($path))] : null;
            default:
                return null;
        }
    }
}
