<?php

namespace App\Services;

use App\Models\HsCode;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class HsCodeSearchService
{
    private const STOP_WORDS = [
        'and', 'the', 'for', 'from', 'with', 'without', 'other', 'than', 'not', 'elsewhere',
        'new', 'goods', 'cargo', 'product', 'products', 'material', 'made', 'used',
    ];

    public function search(?string $query, int $limit = 8): array
    {
        $query = trim((string) $query);
        $limit = max(1, min(25, $limit));
        if ($query === '' || ! Schema::hasTable('hs_code_catalog')) {
            return [];
        }

        $digits = preg_replace('/\D+/', '', $query);
        if (is_string($digits) && strlen($digits) >= 4) {
            $codeMatches = HsCode::query()
                ->where('hs_code', 'like', substr($digits, 0, 6).'%')
                ->limit($limit)
                ->get();
            if ($codeMatches->isNotEmpty()) {
                return $codeMatches->map(fn (HsCode $item): array => $this->result($item, 1.0))->all();
            }
        }

        $normalized = Str::lower(Str::ascii($query));
        $tokens = collect(preg_split('/[^a-z0-9]+/', $normalized) ?: [])
            ->filter(fn (string $token): bool => strlen($token) >= 3 && ! in_array($token, self::STOP_WORDS, true))
            ->unique()
            ->take(10)
            ->values();
        if ($tokens->isEmpty()) {
            return [];
        }

        $candidates = HsCode::query()
            ->where(function ($builder) use ($tokens): void {
                foreach ($tokens as $token) {
                    $builder->orWhere('description', 'like', "%{$token}%")
                        ->orWhere('heading_name', 'like', "%{$token}%")
                        ->orWhere('chapter_name', 'like', "%{$token}%");
                }
            })
            ->limit(500)
            ->get();

        return $candidates
            ->map(function (HsCode $item) use ($normalized, $tokens): array {
                $description = Str::lower(Str::ascii($item->description));
                $heading = Str::lower(Str::ascii($item->heading_name));
                $chapter = Str::lower(Str::ascii($item->chapter_name));
                $score = str_contains($description, $normalized) ? 120 : 0;
                foreach ($tokens as $token) {
                    $score += $this->containsToken($description, $token) ? 28 : 0;
                    $score += $this->containsToken($heading, $token) ? 12 : 0;
                    $score += $this->containsToken($chapter, $token) ? 4 : 0;
                }

                return ['item' => $item, 'score' => $score];
            })
            ->filter(fn (array $candidate): bool => $candidate['score'] > 0)
            ->sortByDesc('score')
            ->take($limit)
            ->map(fn (array $candidate): array => $this->result(
                $candidate['item'],
                min(0.98, max(0.35, $candidate['score'] / 120))
            ))
            ->values()
            ->all();
    }

    private function result(HsCode $item, float $confidence): array
    {
        return [
            'code' => $item->hs_code,
            'description' => $item->description,
            'headingCode' => $item->heading_code,
            'headingName' => $item->heading_name,
            'chapterCode' => $item->chapter_code,
            'chapterName' => $item->chapter_name,
            'version' => $item->version,
            'confidence' => round($confidence, 2),
        ];
    }

    private function containsToken(string $haystack, string $token): bool
    {
        return preg_match('/\b'.preg_quote($token, '/').'\b/i', $haystack) === 1;
    }
}
