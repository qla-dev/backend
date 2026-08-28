<?php

namespace App\Services;

use App\Models\HsCode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class HsCodeSearchService
{
    private const STOP_WORDS = [
        'and', 'the', 'for', 'from', 'with', 'without', 'other', 'than', 'goods', 'product', 'products',
        'und', 'der', 'die', 'das', 'mit', 'ohne', 'andere', 'anderen', 'waren',
        'i', 'ili', 'za', 'od', 'sa', 'bez', 'ostali', 'ostale', 'roba', 'proizvod', 'proizvodi',
    ];

    public function search(
        ?string $query,
        int $limit = 8,
        string $lang = 'en',
        bool $includeHierarchy = false,
    ): array {
        $query = trim((string) $query);
        $limit = max(1, min(25, $limit));
        $lang = in_array($lang, ['en', 'de', 'bs'], true) ? $lang : 'en';
        if ($query === '' || ! Schema::hasTable('hs_code_catalog')) {
            return [];
        }

        $digits = $this->digits($query);
        if (strlen($digits) >= 2) {
            $codeMatches = HsCode::query()
                ->whereNotNull('tariff_code')
                ->whereRaw("REPLACE(tariff_code, ' ', '') LIKE ?", [substr($digits, 0, 10).'%'])
                ->orderBy('id')
                ->limit(500)
                ->get()
                ->filter(fn (HsCode $item): bool => $includeHierarchy || $this->isSelectable($item))
                ->take($limit);

            if ($codeMatches->isNotEmpty()) {
                return $codeMatches
                    ->map(fn (HsCode $item): array => $this->formatItem($item, 1.0, $lang))
                    ->values()
                    ->all();
            }
        }

        $normalized = Str::lower(Str::ascii($query));
        $tokens = collect(preg_split('/[^a-z0-9]+/', $normalized) ?: [])
            ->filter(fn (string $token): bool => strlen($token) >= 2 && ! in_array($token, self::STOP_WORDS, true))
            ->unique()
            ->take(10)
            ->values();
        if ($tokens->isEmpty()) {
            return [];
        }

        $candidates = HsCode::query()
            ->where(function (Builder $builder) use ($tokens): void {
                foreach ($tokens as $token) {
                    foreach (['name', 'section', 'chapter', 'full_name', 'full_name_en', 'full_name_de'] as $column) {
                        $builder->orWhere($column, 'like', "%{$token}%");
                    }
                }
            })
            ->limit(1200)
            ->get();

        return $candidates
            ->filter(fn (HsCode $item): bool => $includeHierarchy || $this->isSelectable($item))
            ->map(function (HsCode $item) use ($lang, $normalized, $tokens): array {
                $localized = Str::lower(Str::ascii($this->localizedPath($item, $lang)));
                $allText = Str::lower(Str::ascii(implode(' ', array_filter([
                    $item->name, $item->section, $item->chapter,
                    $item->full_name, $item->full_name_en, $item->full_name_de,
                ]))));
                $score = str_contains($localized, $normalized) ? 120 : 0;
                $score += str_contains($allText, $normalized) ? 50 : 0;
                foreach ($tokens as $token) {
                    $score += $this->containsToken($localized, $token) ? 30 : 0;
                    $score += $this->containsToken($allText, $token) ? 12 : 0;
                }
                if ($this->isSelectable($item)) {
                    $score += 5;
                }

                return ['item' => $item, 'score' => $score];
            })
            ->filter(fn (array $candidate): bool => $candidate['score'] > 0)
            ->sort(function (array $left, array $right): int {
                return $right['score'] <=> $left['score'] ?: $left['item']->id <=> $right['item']->id;
            })
            ->take($limit)
            ->map(fn (array $candidate): array => $this->formatItem(
                $candidate['item'],
                min(0.98, max(0.35, $candidate['score'] / 170)),
                $lang,
            ))
            ->values()
            ->all();
    }

    public function resolveByCodes(array $codes, string $lang = 'en'): array
    {
        if (! Schema::hasTable('hs_code_catalog')) {
            return [];
        }

        $normalized = collect($codes)
            ->map(fn ($code): string => $this->digits((string) $code))
            ->filter(fn (string $code): bool => strlen($code) >= 4)
            ->unique()
            ->values();
        if ($normalized->isEmpty()) {
            return [];
        }

        $matches = HsCode::query()
            ->whereNotNull('tariff_code')
            ->where(function (Builder $builder) use ($normalized): void {
                foreach ($normalized as $code) {
                    $builder->orWhereRaw("REPLACE(tariff_code, ' ', '') = ?", [$code]);
                }
            })
            ->orderBy('id')
            ->get()
            ->unique(fn (HsCode $item): string => $this->digits((string) $item->tariff_code))
            ->keyBy(fn (HsCode $item): string => $this->digits((string) $item->tariff_code));

        return $normalized
            ->map(fn (string $code): ?array => $matches->has($code)
                ? $this->formatItem($matches->get($code), 1.0, $lang)
                : null)
            ->filter()
            ->values()
            ->all();
    }

    public function formatItem(HsCode $item, float $confidence, string $lang): array
    {
        $code = (string) ($item->tariff_code ?? '');
        $path = $this->localizedPath($item, $lang);
        $parts = array_values(array_filter(array_map('trim', explode('>>>', $path))));
        $name = $parts !== [] ? end($parts) : (string) ($item->name ?? '');
        $digits = $this->digits($code);

        return [
            'catalogId' => $item->id,
            'code' => $code,
            'name' => $name,
            'description' => $name,
            'fullName' => $path,
            'parentCode' => $item->previous_tariff_code,
            'section' => $parts[0] ?? (string) ($item->section ?? ''),
            'chapterCode' => substr($digits, 0, 2),
            'chapterName' => $parts[1] ?? (string) ($item->chapter ?? ''),
            'headingCode' => substr($digits, 0, 4),
            'headingName' => $name,
            'depth' => max(0, count($parts) - 1),
            'selectable' => strlen($digits) === 10,
            'confidence' => round($confidence, 2),
        ];
    }

    private function localizedPath(HsCode $item, string $lang): string
    {
        return (string) match ($lang) {
            'de' => $item->full_name_de ?: $item->full_name_en ?: $item->full_name,
            'bs' => $item->full_name ?: $item->full_name_en ?: $item->full_name_de,
            default => $item->full_name_en ?: $item->full_name ?: $item->full_name_de,
        };
    }

    private function isSelectable(HsCode $item): bool
    {
        return strlen($this->digits((string) $item->tariff_code)) === 10;
    }

    private function digits(string $value): string
    {
        return (string) preg_replace('/\D+/', '', $value);
    }

    private function containsToken(string $haystack, string $token): bool
    {
        return preg_match('/\b'.preg_quote($token, '/').'\b/i', $haystack) === 1;
    }
}
