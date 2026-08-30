<?php

namespace App\Services;

use Illuminate\Support\Collection;

class CustomsDocumentCatalog
{
    private ?Collection $documents = null;

    public function all(): Collection
    {
        return $this->documents ??= collect(json_decode(
            file_get_contents(resource_path('data/customs-document-types.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        ));
    }

    public function catalog(?string $search = null): array
    {
        $needle = mb_strtolower(trim((string) $search));

        return $this->all()
            ->when($needle !== '', fn (Collection $documents) => $documents->filter(
                fn (array $document): bool => str_contains(mb_strtolower((string) ($document['value'] ?? '')), $needle)
                    || str_contains(mb_strtolower((string) ($document['label'] ?? '')), $needle)
            ))
            ->map(fn (array $document): array => $this->present($document))
            ->values()
            ->all();
    }

    public function matching(array $codes): array
    {
        $normalizedCodes = collect($codes)
            ->map(fn (string $code): string => $this->normalizeCode($code))
            ->filter()
            ->unique()
            ->values();

        return $this->all()
            ->filter(function (array $document) use ($normalizedCodes): bool {
                if ((int) ($document['standard'] ?? 0) === 1) {
                    return true;
                }

                $tariffs = $document['tariffs'] ?? [];
                if (! is_array($tariffs) || $tariffs === []) {
                    return false;
                }

                $normalizedTariffs = collect($tariffs)
                    ->map(fn (string $tariff): string => $this->normalizeCode($tariff))
                    ->filter();

                // Preserve Deklarant's precedence: exact tariff match first, then prefix match.
                if ($normalizedTariffs->contains(fn (string $tariff): bool => $normalizedCodes->contains($tariff))) {
                    return true;
                }

                return $normalizedTariffs->contains(
                    fn (string $tariff): bool => $normalizedCodes->contains(
                        fn (string $code): bool => str_starts_with($code, $tariff)
                    )
                );
            })
            ->unique('value')
            ->map(fn (array $document): array => [...$this->present($document), 'source' => 'matched'])
            ->values()
            ->all();
    }

    public function resolve(array $codes, array $stored = []): array
    {
        $matched = $this->matching($codes);
        $matchedCodes = array_column($matched, 'code');
        $manual = collect($stored)
            ->filter(fn (mixed $document): bool => is_array($document)
                && ($document['source'] ?? null) === 'manual'
                && filled($document['code'] ?? null)
                && ! in_array((string) $document['code'], $matchedCodes, true))
            ->map(function (array $document): array {
                $catalogDocument = $this->find((string) $document['code']);

                return $catalogDocument
                    ? [...$this->present($catalogDocument), 'source' => 'manual']
                    : [
                        'code' => (string) $document['code'],
                        'label' => (string) ($document['label'] ?? $document['code']),
                        'downloadable' => false,
                        'source' => 'manual',
                    ];
            })
            ->values()
            ->all();

        return [...$matched, ...$manual];
    }

    public function find(string $code): ?array
    {
        return $this->all()->first(
            fn (array $document): bool => (string) ($document['value'] ?? '') === $code
        );
    }

    public function templateFilename(array $document): ?string
    {
        return match ($document['route'] ?? null) {
            'generate-dis' => 'dis.docx',
            'generate-osi' => 'osi.docx',
            'generate-zut' => 'zut.docx',
            'generate-dv1' => 'dv1.docx',
            'generate-znp' => 'znp.docx',
            default => null,
        };
    }

    private function present(array $document): array
    {
        return [
            'code' => (string) ($document['value'] ?? ''),
            'label' => (string) ($document['label'] ?? ''),
            'downloadable' => (int) ($document['download'] ?? 0) === 1
                && $this->templateFilename($document) !== null,
        ];
    }

    private function normalizeCode(string $code): string
    {
        return (string) preg_replace('/\s+/', '', trim($code));
    }
}
