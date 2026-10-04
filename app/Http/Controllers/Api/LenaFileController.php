<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Knowledge files for LenaAI (module briefs, schema dumps, specifications). An uploaded .json or
 * .txt file gets its own route, /api/lena-files/{slug}, named after the file. Uploading the same
 * name again replaces the content at the same route. Files live on the local disk, not in the DB.
 */
class LenaFileController extends Controller
{
    private const DIR = 'lena-files';

    public function index(): JsonResponse
    {
        $rows = collect(Storage::disk('local')->files(self::DIR))
            ->map(fn (string $path) => $this->meta(pathinfo($path, PATHINFO_FILENAME), pathinfo($path, PATHINFO_EXTENSION)))
            ->sortByDesc('updated_at')->values()->all();

        return response()->json(['message' => 'Lena files retrieved.', 'data' => $rows, 'meta' => [], 'errors' => []]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:10240', 'mimetypes:application/json,text/plain,text/json']]);
        $file = $request->file('file');
        $extension = strtolower($file->getClientOriginalExtension());
        if (! in_array($extension, ['json', 'txt'], true)) {
            throw ValidationException::withMessages(['file' => ['Only .json or .txt files can be uploaded.']]);
        }
        $content = (string) file_get_contents($file->getRealPath());
        if (! mb_check_encoding($content, 'UTF-8')) {
            throw ValidationException::withMessages(['file' => ['The file must be UTF-8 text.']]);
        }
        if ($extension === 'json') {
            json_decode($content);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw ValidationException::withMessages(['file' => ['Invalid JSON: '.json_last_error_msg()]]);
            }
        }
        $slug = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));
        if ($slug === '') {
            throw ValidationException::withMessages(['file' => ['The file name must contain letters or digits.']]);
        }
        // One route per name: a .json and .txt with the same name would compete for it.
        Storage::disk('local')->delete(self::DIR.'/'.$slug.'.'.($extension === 'json' ? 'txt' : 'json'));
        Storage::disk('local')->put(self::DIR.'/'.$slug.'.'.$extension, $content);

        return response()->json(['message' => 'Lena file uploaded.', 'data' => $this->meta($slug, $extension), 'meta' => [], 'errors' => []], 201);
    }

    public function show(string $slug): JsonResponse
    {
        foreach (['json', 'txt'] as $extension) {
            $path = self::DIR.'/'.$slug.'.'.$extension;
            if (Storage::disk('local')->exists($path)) {
                $content = Storage::disk('local')->get($path);

                return response()->json(['message' => 'Lena file retrieved.', 'data' => $this->meta($slug, $extension)
                    + ['content' => $extension === 'json' ? json_decode($content, true) : $content], 'meta' => [], 'errors' => []]);
            }
        }
        abort(404);
    }

    public function destroy(string $slug): JsonResponse
    {
        $deleted = Storage::disk('local')->delete([self::DIR.'/'.$slug.'.json', self::DIR.'/'.$slug.'.txt']);
        abort_unless($deleted, 404);

        return response()->json(['message' => 'Lena file deleted.', 'data' => null, 'meta' => [], 'errors' => []]);
    }

    private function meta(string $slug, string $extension): array
    {
        $path = self::DIR.'/'.$slug.'.'.$extension;

        return ['slug' => $slug, 'type' => $extension, 'route' => '/api/lena-files/'.$slug, 'size_bytes' => Storage::disk('local')->size($path),
            'updated_at' => date(DATE_ATOM, Storage::disk('local')->lastModified($path))];
    }
}
