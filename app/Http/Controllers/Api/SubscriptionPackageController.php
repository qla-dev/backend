<?php

namespace App\Http\Controllers\Api;

use App\Models\SubscriptionPackage;
use Illuminate\Database\Eloquent\Builder;

class SubscriptionPackageController extends CrudController
{
    protected function modelClass(): string
    {
        return SubscriptionPackage::class;
    }

    protected function searchColumns(): array
    {
        return ['name', 'slug'];
    }

    protected function applyFilters(Builder $query, \Illuminate\Http\Request $request): void
    {
        if (! $request->user()?->isSuperAdminOrMaster()) {
            $query->where('is_active', true);
        }
    }

    protected function applyOrdering(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('id');
    }

    protected function rules(bool $updating = false): array
    {
        $p = $updating ? 'sometimes' : 'required';

        return [
            'name' => [$p, 'string', 'max:120'],
            'slug' => [$p, 'string', 'max:120', $updating ? 'sometimes' : 'unique:subscription_packages,slug'],
            'tagline' => ['nullable', 'string', 'max:255'],
            'price_monthly' => [$p, 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'lena_ai_tokens' => [$p, 'integer', 'min:0'],
            'icon' => [$p, 'string', 'max:60'],
            'color' => [$p, 'string', 'max:120'],
            'features' => ['nullable', 'array'],
            'features.*.key' => ['required_with:features', 'string', 'max:80'],
            'features.*.title' => ['required_with:features', 'string', 'max:160'],
            'features.*.description' => ['nullable', 'string', 'max:500'],
            'features.*.icon' => ['nullable', 'string', 'max:60'],
            'features.*.roles' => ['nullable', 'array'],
            'is_popular' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
