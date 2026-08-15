<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EntityResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

abstract class CrudController extends Controller
{
    /** @return class-string<Model> */
    abstract protected function modelClass(): string;

    /** @return array<string, mixed> */
    abstract protected function rules(bool $updating = false): array;

    /** @return list<string> */
    protected function relations(): array
    {
        return [];
    }

    /** @return list<string> */
    protected function searchColumns(): array
    {
        return [];
    }

    protected function applyFilters(Builder $query, Request $request): void
    {
    }

    public function index(Request $request): JsonResponse
    {
        $model = $this->modelClass();
        $query = $model::query()->with($this->relations());
        $search = trim((string) $request->query('search', ''));

        if ($search !== '' && $this->searchColumns() !== []) {
            $query->where(function ($builder) use ($search): void {
                foreach ($this->searchColumns() as $index => $column) {
                    $method = $index === 0 ? 'where' : 'orWhere';
                    $builder->{$method}($column, 'like', "%{$search}%");
                }
            });
        }

        $this->applyFilters($query, $request);

        $perPage = max(1, min(500, (int) $request->query('limit', $request->query('per_page', 25))));
        $pageNumber = max(1, (int) $request->query('pageno', $request->query('page_no', $request->query('page', 1))));
        $page = $query->latest('id')->paginate($perPage, ['*'], 'page', $pageNumber);

        return $this->success(
            EntityResource::collection($page->items())->resolve($request),
            'Resources retrieved successfully.',
            [
                'current_page' => $page->currentPage(), 'page_no' => $page->currentPage(),
                'last_page' => $page->lastPage(), 'per_page' => $page->perPage(),
                'limit' => $page->perPage(), 'total' => $page->total(),
            ]
        );
    }

    public function store(Request $request): JsonResponse
    {
        $model = $this->modelClass();
        $record = $model::query()->create($request->validate($this->rules()));
        $record->load($this->relations());

        return $this->success((new EntityResource($record))->resolve($request), 'Resource created successfully.', status: 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $model = $this->modelClass();
        $record = $model::query()->with($this->relations())->findOrFail($id);

        return $this->success((new EntityResource($record))->resolve($request), 'Resource retrieved successfully.');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $model = $this->modelClass();
        $record = $model::query()->findOrFail($id);
        $record->update($request->validate($this->rules(true)));
        $record->load($this->relations());

        return $this->success((new EntityResource($record))->resolve($request), 'Resource updated successfully.');
    }

    public function destroy(int $id): JsonResponse
    {
        $model = $this->modelClass();
        $model::query()->findOrFail($id)->delete();

        return $this->success(null, 'Resource deleted successfully.');
    }

    protected function success(mixed $data, string $message, array $meta = [], int $status = 200): JsonResponse
    {
        return response()->json(['message' => $message, 'data' => $data, 'meta' => $meta, 'errors' => []], $status);
    }
}
