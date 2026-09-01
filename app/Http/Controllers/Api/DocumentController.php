<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\EntityResource;
use App\Models\Document;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Load paperwork - the CMR, invoice, packing list and so on that a company or driver photographs,
 * scans or drags in. A document usually belongs to a load, but not always: a company keeping its
 * own archive uploads with no load attached, which is why `load_id` stays nullable and the listing
 * can be filtered down to exactly those rows.
 */
class DocumentController extends CrudController
{
    // Types a browser renders in a tab; everything else is handed over as a download.
    private const INLINE_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'];

    protected function modelClass(): string
    {
        return Document::class;
    }

    protected function relations(): array
    {
        return ['freightLoad', 'loadDraft', 'vehicle', 'user', 'uploader'];
    }

    protected function searchColumns(): array
    {
        return ['name', 'type', 'reference'];
    }

    protected function rules(bool $u = false): array
    {
        $p = $u ? 'sometimes' : 'required';

        return ['load_id' => ['nullable', 'integer', 'exists:loads,id'], 'load_draft_id' => ['nullable', 'integer', 'exists:load_drafts,id'], 'vehicle_id' => ['nullable', 'integer', 'exists:vehicles,id'], 'user_id' => ['nullable', 'integer', 'exists:users,id'], 'uploaded_by_user_id' => [$p, 'integer', 'exists:users,id'], 'type' => [$p, 'string', 'max:100'], 'name' => [$p, 'string', 'max:255'], 'reference' => ['nullable', 'string', 'max:120'], 'path' => [$p, 'string', 'max:500'], 'mime_type' => ['nullable', 'string', 'max:120'], 'size_bytes' => ['nullable', 'integer', 'min:0'], 'expires_at' => ['nullable', 'date']];
    }

    protected function applyFilters(Builder $query, Request $request): void
    {
        if ($request->filled('load_id')) {
            $query->where('load_id', $request->integer('load_id'));
        }

        if ($request->filled('load_draft_id')) {
            $query->where('load_draft_id', $request->integer('load_draft_id'));
        }

        // The Documents page splits its list the way the app itself does: paperwork that belongs to
        // a published load, and paperwork still sitting on an unfinished draft. Archive rows (no
        // load and no draft) stay with the published side, since that is where a company keeps its
        // own filing.
        if ($request->filled('scope')) {
            $scope = $request->string('scope')->toString();
            if ($scope === 'draft') {
                $query->whereNotNull('load_draft_id');
            } elseif ($scope === 'published') {
                $query->whereNull('load_draft_id');
            }
        }

        if ($request->filled('vehicle_id')) {
            $query->where('vehicle_id', $request->integer('vehicle_id'));
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->integer('user_id'));
        }

        if ($request->filled('type')) {
            $query->where('type', $request->string('type'));
        }

        // The company's own archive: everything it uploaded without pinning it to a load.
        if ($request->boolean('unassigned')) {
            $query->whereNull('load_id');
        }
    }

    protected function applyOrdering(Builder $query, Request $request): void
    {
        $query->orderByDesc('created_at')->orderByDesc('id');
    }

    /**
     * Upload the file and record it in one request. `load_id` is optional on purpose - leaving it
     * out is how a document lands in the archive rather than on a load.
     */
    public function upload(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:25600'],
            'load_id' => ['nullable', 'integer', 'exists:loads,id'],
            'load_draft_id' => ['nullable', 'integer', 'exists:load_drafts,id'],
            'vehicle_id' => ['nullable', 'integer', 'exists:vehicles,id'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'type' => ['nullable', 'string', 'max:100'],
            'name' => ['nullable', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:120'],
            'expires_at' => ['nullable', 'date'],
        ]);

        // A personal document may only ever be filed against the uploader's own account.
        if (isset($data['user_id']) && (int) $data['user_id'] !== (int) $request->user()->id) {
            abort(403, 'Personal documents can only be uploaded for your own account.');
        }

        $file = $request->file('file');
        $extension = strtolower((string) ($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin'));
        $filename = Str::uuid()->toString().'.'.$extension;
        // Archive uploads have no load to file under, so they share a folder of their own. A
        // draft's paperwork is stored beside it and moves nowhere when the draft is published - the
        // row's load_id is what changes, not where the file lives.
        $folder = isset($data['load_id'])
            ? "loads/{$data['load_id']}"
            : (isset($data['load_draft_id'])
                ? "drafts/{$data['load_draft_id']}"
            : (isset($data['vehicle_id'])
                ? "vehicles/{$data['vehicle_id']}"
                : (isset($data['user_id']) ? "users/{$data['user_id']}" : 'archive')));
        $file->storeAs("documents/{$folder}", $filename, 'local');

        $document = Document::query()->create([
            'load_id' => $data['load_id'] ?? null,
            'load_draft_id' => $data['load_draft_id'] ?? null,
            'vehicle_id' => $data['vehicle_id'] ?? null,
            'user_id' => $data['user_id'] ?? null,
            'uploaded_by_user_id' => $request->user()->id,
            'type' => ($data['type'] ?? '') ?: 'OTHER',
            'name' => ($data['name'] ?? '') ?: $file->getClientOriginalName(),
            'reference' => $data['reference'] ?? null,
            'path' => "{$folder}/{$filename}",
            'mime_type' => $file->getClientMimeType() ?: 'application/octet-stream',
            'size_bytes' => $file->getSize(),
            'expires_at' => $data['expires_at'] ?? null,
        ]);
        $document->load($this->relations());

        return $this->success((new EntityResource($document))->resolve($request), 'Document uploaded successfully.', status: 201);
    }

    public function download(Request $request, int $id): StreamedResponse
    {
        $document = Document::query()->findOrFail($id);
        $path = "documents/{$document->path}";
        abort_unless(Storage::disk('local')->exists($path), 404);

        $extension = strtolower((string) pathinfo($document->path, PATHINFO_EXTENSION));
        $disposition = $request->boolean('inline') && in_array($extension, self::INLINE_EXTENSIONS, true) ? 'inline' : 'attachment';

        return Storage::disk('local')->response($path, $document->name, [], $disposition);
    }

    /**
     * Removing the row should not leave the file behind - a document is the file, not a pointer
     * somebody else still owns.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $document = Document::query()->findOrFail($id);
        Storage::disk('local')->delete("documents/{$document->path}");
        $document->delete();

        return $this->success(null, 'Document deleted successfully.');
    }
}
