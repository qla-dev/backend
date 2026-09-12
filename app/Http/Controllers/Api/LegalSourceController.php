<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\LegalSourceCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class LegalSourceController extends Controller
{
    /** The manifest as a searchable, paginated table for the superadmin "Zakoni" screen. */
    public function index(Request $request, LegalSourceCatalog $catalog): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:200'],
            'jurisdiction' => ['sometimes', 'nullable', 'string', 'in:'.implode(',', array_keys(LegalSourceCatalog::JURISDICTIONS))],
            'status' => ['sometimes', 'nullable', 'string', 'in:stored,link,manual'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:10', 'max:100'],
        ]);

        $all = collect($catalog->sources())->map(fn (array $source): array => $this->row($source, $catalog));
        $needle = Str::lower(Str::ascii(trim((string) ($validated['search'] ?? ''))));
        $rows = $all
            ->when(filled($validated['jurisdiction'] ?? null), fn ($rows) => $rows->where('jurisdiction', $validated['jurisdiction']))
            ->when(filled($validated['status'] ?? null), fn ($rows) => $rows->where('status', $validated['status']))
            ->when($needle !== '', fn ($rows) => $rows->filter(fn (array $row): bool => Str::contains(
                Str::lower(Str::ascii(implode(' ', [$row['id'], $row['title'], $row['publisher'], $row['file']]))),
                $needle
            )))
            ->values();

        $perPage = (int) ($validated['per_page'] ?? 50);
        $lastPage = max(1, (int) ceil($rows->count() / $perPage));
        $page = min((int) ($validated['page'] ?? 1), $lastPage);

        return response()->json([
            'message' => 'Legal sources retrieved.',
            'data' => $rows->forPage($page, $perPage)->values(),
            'meta' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $rows->count(),
                'jurisdictions' => $all->countBy('jurisdiction'),
                'stored' => $all->where('status', 'stored')->count(),
                'link_only' => $all->where('status', 'link')->count(),
                'manual' => $all->where('status', 'manual')->count(),
            ],
            'errors' => [],
        ]);
    }

    public function show(string $source, LegalSourceCatalog $catalog): BinaryFileResponse|RedirectResponse
    {
        $entry = $catalog->find($source);
        abort_unless($entry, 404);

        // Documents too large to keep in the repository open at the publisher instead.
        if (($entry['stored'] ?? true) === false) {
            return redirect()->away($entry['url']);
        }

        $path = $catalog->path($entry);
        abort_unless(is_file($path), 404);

        return response()->file($path, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$entry['file'].'"']);
    }

    private function row(array $source, LegalSourceCatalog $catalog): array
    {
        $stored = ($source['stored'] ?? true) !== false;
        $path = $catalog->path($source);

        return [
            'id' => $source['id'],
            'jurisdiction' => $source['jurisdiction'],
            'jurisdictionName' => LegalSourceCatalog::JURISDICTIONS[$source['jurisdiction']] ?? $source['jurisdiction'],
            'title' => $source['title'],
            'publisher' => $source['publisher'] ?? null,
            'folder' => $source['folder'],
            'file' => $source['file'],
            'format' => $source['format'] ?? 'pdf',
            'url' => $source['url'] ?? null,
            'page' => $source['page'] ?? null,
            // manual: no download URL to refresh from; link: opens at the publisher; stored: served locally.
            'status' => empty($source['url']) ? 'manual' : ($stored ? 'stored' : 'link'),
            'autoDiscover' => ! empty($source['link_pattern']),
            'bytes' => $stored && is_file($path) ? filesize($path) : null,
            'retrieved' => $source['retrieved'] ?? null,
            'note' => $source['note'] ?? null,
        ];
    }
}
