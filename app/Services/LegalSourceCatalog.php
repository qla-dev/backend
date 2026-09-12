<?php

namespace App\Services;

/**
 * The curated customs-law library available to Lena's legal-consultation mode, split by jurisdiction.
 * The manifest records where each document was downloaded from, so legal-sources:refresh can fetch
 * updated versions again.
 */
class LegalSourceCatalog
{
    public const MANIFEST = 'agents/lena/legal-sources.json';

    public const JURISDICTIONS = [
        'BA' => 'Bosnia and Herzegovina',
        'EU' => 'European Union',
        'HR' => 'Croatia',
        'RS' => 'Serbia',
    ];

    private ?array $sources = null;

    public function sources(): array
    {
        return $this->sources ??= $this->manifest()['sources'];
    }

    public function manifest(): array
    {
        return json_decode((string) file_get_contents(base_path(self::MANIFEST)), true, 512, JSON_THROW_ON_ERROR);
    }

    public function find(string $id): ?array
    {
        foreach ($this->sources() as $source) {
            if ($source['id'] === $id) return $source;
        }
        return null;
    }

    public function path(array $source): string
    {
        return base_path('agents'.DIRECTORY_SEPARATOR.'lena'.DIRECTORY_SEPARATOR.$source['folder'].DIRECTORY_SEPARATOR.'documents'.DIRECTORY_SEPARATOR.$source['file']);
    }

    public function promptCatalog(): string
    {
        return collect($this->sources())
            ->map(fn (array $source) => "{$source['id']} [".self::JURISDICTIONS[$source['jurisdiction']]."]: {$source['title']}")
            ->implode("\n");
    }
}
