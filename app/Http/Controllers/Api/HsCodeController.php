<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HsCode;
use App\Services\HsCodeSearchService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class HsCodeController extends Controller
{
    public function categories(Request $request, HsCodeSearchService $search): JsonResponse
    {
        $validated = $request->validate([
            'lang' => ['sometimes', 'string', 'in:en,de,bs'],
            'section' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);
        $lang = (string) ($validated['lang'] ?? 'en');
        $section = $validated['section'] ?? null;
        $rows = HsCode::query()
            ->when(filled($section), fn (Builder $query) => $query->where('section', $section))
            ->orderBy('id')
            ->get();
        $groupColumn = filled($section) ? 'chapter' : 'section';
        $categories = $rows
            ->groupBy(fn (HsCode $item): string => (string) $item->{$groupColumn})
            ->map(function ($items, string $id) use ($lang, $search, $groupColumn): array {
                $first = $items->first();
                $formatted = $search->formatItem($first, 1.0, $lang);

                return [
                    'id' => $id,
                    'label' => $groupColumn === 'chapter'
                        ? ($formatted['chapterName'] ?: $id)
                        : ($formatted['section'] ?: $id),
                    'count' => $items->count(),
                    'selectableCount' => $items->filter(fn (HsCode $item): bool => strlen($this->digits((string) $item->tariff_code)) === 10)->count(),
                ];
            })
            ->values();

        return response()->json([
            'message' => 'Tariff categories retrieved.',
            'data' => $categories,
            'meta' => [
                'total' => $rows->count(),
                'categories' => $categories->count(),
                'coded' => $rows->whereNotNull('tariff_code')->count(),
                'selectable' => $categories->sum('selectableCount'),
                'parent_section' => $section,
            ],
            'errors' => [],
        ]);
    }

    public function catalog(Request $request, HsCodeSearchService $search): JsonResponse
    {
        $validated = $request->validate([
            'query' => ['sometimes', 'nullable', 'string', 'max:300'],
            'section' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'chapter' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'lang' => ['sometimes', 'string', 'in:en,de,bs'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:10', 'max:100'],
        ]);
        $lang = (string) ($validated['lang'] ?? 'en');
        $query = HsCode::query();

        if (filled($validated['section'] ?? null)) {
            $query->where('section', $validated['section']);
        }
        if (filled($validated['chapter'] ?? null)) {
            $query->where('chapter', $validated['chapter']);
        }
        if (filled($validated['query'] ?? null)) {
            $this->applyCatalogSearch($query, (string) $validated['query']);
        }

        $paginator = $query->orderBy('id')->paginate((int) ($validated['per_page'] ?? 50));
        $paginator->setCollection($paginator->getCollection()->map(function (HsCode $item) use ($lang, $search): array {
            return [
                ...$search->formatItem($item, 1.0, $lang),
                'ex' => $item->ex,
                'sourceName' => $item->name,
            ];
        }));

        return response()->json([
            'message' => 'Tariff catalog retrieved.',
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'errors' => [],
        ]);
    }

    public function index(Request $request, HsCodeSearchService $search): JsonResponse
    {
        $validated = $request->validate([
            'query' => ['required', 'string', 'min:2', 'max:300'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:25'],
            'lang' => ['sometimes', 'string', 'in:en,de,bs'],
        ]);

        $results = $search->search(
            $validated['query'],
            (int) ($validated['limit'] ?? 8),
            (string) ($validated['lang'] ?? 'en'),
            true,
        );

        return response()->json([
            'message' => 'HS codes retrieved.',
            'data' => $results,
            'meta' => ['total' => count($results)],
            'errors' => [],
        ]);
    }

    // Batch-resolves a set of already-known codes back to their full catalog entry (description,
    // category names) in one request - used when opening an existing load for editing, since only
    // the bare codes are persisted on the load itself.
    public function bulk(Request $request, HsCodeSearchService $search): JsonResponse
    {
        $validated = $request->validate([
            'codes' => ['required', 'array', 'min:1', 'max:100'],
            'codes.*' => ['string'],
            'lang' => ['sometimes', 'string', 'in:en,de,bs'],
        ]);

        $results = $search->resolveByCodes($validated['codes'], (string) ($validated['lang'] ?? 'en'));

        return response()->json([
            'message' => 'HS codes retrieved.',
            'data' => $results,
            'meta' => ['total' => count($results)],
            'errors' => [],
        ]);
    }

    private function applyCatalogSearch(Builder $query, string $value): void
    {
        $value = trim($value);
        $digits = $this->digits($value);
        if (strlen($digits) >= 2 && preg_match('/^\s*\d[\d\s.\/-]*$/u', $value) === 1) {
            $query->whereNotNull('tariff_code')
                ->whereRaw("REPLACE(tariff_code, ' ', '') LIKE ?", [substr($digits, 0, 10).'%']);

            return;
        }

        collect(preg_split('/\s+/', Str::lower($value)) ?: [])
            ->filter(fn (string $token): bool => strlen($token) >= 2)
            ->each(function (string $token) use ($query): void {
                $query->where(function (Builder $builder) use ($token): void {
                    foreach (['name', 'section', 'chapter', 'full_name', 'full_name_en', 'full_name_de'] as $column) {
                        $builder->orWhere($column, 'like', "%{$token}%");
                    }
                });
            });
    }

    private function digits(string $value): string
    {
        return (string) preg_replace('/\D+/', '', $value);
    }
}
