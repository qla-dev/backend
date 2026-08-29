<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Driver;
use App\Models\Load;
use App\Models\Review;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ReviewController extends Controller
{
    private const TARGETS = [
        'company' => Company::class,
        'warehouse' => Warehouse::class,
        'customer' => Customer::class,
        'driver' => Driver::class,
        'load' => Load::class,
    ];

    private const CRITERIA = [
        'company' => ['communication', 'reliability', 'service_quality'],
        'warehouse' => ['facility_quality', 'handling_efficiency', 'staff_communication'],
        'customer' => ['communication', 'payment_reliability', 'cooperation'],
        'driver' => ['punctuality', 'cargo_care', 'communication'],
        'load' => ['description_accuracy', 'route_readiness', 'execution'],
    ];

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'reviewable_type' => ['required', Rule::in(array_keys(self::TARGETS))],
            'reviewable_id' => ['required', 'integer', 'min:1'],
        ]);
        $target = $this->target($data['reviewable_type'], (int) $data['reviewable_id']);

        return $this->summary($request, $data['reviewable_type'], $target);
    }

    public function store(Request $request): JsonResponse
    {
        if ($request->user()->isSuperAdminOrMaster()) {
            abort(403, 'Administrator accounts cannot submit reviews.');
        }

        $data = $request->validate([
            'reviewable_type' => ['required', Rule::in(array_keys(self::TARGETS))],
            'reviewable_id' => ['required', 'integer', 'min:1'],
            'rating' => ['required', 'integer', 'between:1,5'],
            'criteria' => ['required', 'array', 'size:3'],
            'criteria.*' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:4000'],
        ]);
        $target = $this->target($data['reviewable_type'], (int) $data['reviewable_id']);
        $this->assertNotOwnProfile($request, $data['reviewable_type'], $target);

        $expectedCriteria = self::CRITERIA[$data['reviewable_type']];
        $submittedCriteria = array_keys($data['criteria']);
        sort($expectedCriteria);
        sort($submittedCriteria);
        if ($submittedCriteria !== $expectedCriteria) {
            throw ValidationException::withMessages([
                'criteria' => ['The review criteria do not match this review type.'],
            ]);
        }

        if (Review::query()
            ->where('reviewer_user_id', $request->user()->id)
            ->where('reviewable_type', $target::class)
            ->where('reviewable_id', $target->getKey())
            ->exists()) {
            throw ValidationException::withMessages([
                'review' => ['You have already reviewed this profile.'],
            ]);
        }

        Review::query()->create([
            'reviewer_user_id' => $request->user()->id,
            'reviewable_type' => $target::class,
            'reviewable_id' => $target->getKey(),
            'mode' => $data['reviewable_type'],
            'rating' => $data['rating'],
            'criteria' => $data['criteria'],
            'comment' => trim((string) ($data['comment'] ?? '')) ?: null,
        ]);

        if ($target instanceof Driver) {
            $target->update(['rating' => round((float) $target->reviews()->avg('rating'), 2)]);
        }

        return $this->summary($request, $data['reviewable_type'], $target, 201);
    }

    private function summary(Request $request, string $mode, Model $target, int $status = 200): JsonResponse
    {
        $reviews = Review::query()
            ->where('reviewable_type', $target::class)
            ->where('reviewable_id', $target->getKey())
            ->with('reviewer:id,name,avatar_url')
            ->latest()
            ->get();
        $hasReviewed = $reviews->contains('reviewer_user_id', $request->user()->id);
        $canReview = ! $request->user()->isSuperAdminOrMaster()
            && ! $hasReviewed
            && ! $this->isOwnProfile($request, $mode, $target);

        return response()->json([
            'message' => $status === 201 ? 'Review submitted successfully.' : 'Reviews retrieved successfully.',
            'data' => $reviews,
            'meta' => [
                'average_rating' => round((float) ($reviews->avg('rating') ?? 0), 2),
                'total' => $reviews->count(),
                'has_reviewed' => $hasReviewed,
                'can_review' => $canReview,
            ],
            'errors' => [],
        ], $status);
    }

    private function target(string $mode, int $id): Model
    {
        $model = self::TARGETS[$mode];

        return $model::query()->findOrFail($id);
    }

    private function assertNotOwnProfile(Request $request, string $mode, Model $target): void
    {
        if ($this->isOwnProfile($request, $mode, $target)) {
            throw ValidationException::withMessages([
                'review' => ['You cannot review your own profile.'],
            ]);
        }
    }

    private function isOwnProfile(Request $request, string $mode, Model $target): bool
    {
        $userId = (int) $request->user()->id;

        return match ($mode) {
            'company' => (int) $target->owner_user_id === $userId
                || $target->users()->whereKey($userId)->exists(),
            'warehouse' => (int) $target->user_id === $userId,
            'customer', 'driver' => (int) $target->user_id === $userId,
            // A load is the shared transaction being reviewed, not a person's own profile.
            'load' => false,
            default => true,
        };
    }
}
