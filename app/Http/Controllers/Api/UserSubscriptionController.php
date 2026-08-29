<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionPackage;
use App\Models\User;
use App\Models\UserSubscription;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserSubscriptionController extends Controller
{
    public function index(): JsonResponse
    {
        $subscriptions = UserSubscription::with(['user:id,name,username,email', 'subscriptionPackage'])->latest('id')->get();

        return response()->json(['message' => 'Subscriptions retrieved successfully.', 'data' => $subscriptions, 'meta' => [], 'errors' => []]);
    }

    // The current user's own subscription (God Mode roles have unlimited access without a row).
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user?->isSuperAdminOrMaster()) {
            return response()->json(['message' => 'Unlimited access.', 'data' => null, 'meta' => ['unlimited' => true], 'errors' => []]);
        }

        $subscription = UserSubscription::with('subscriptionPackage')->where('user_id', $user->id)->first();

        return response()->json(['message' => 'Subscription retrieved successfully.', 'data' => $subscription, 'meta' => [], 'errors' => []]);
    }

    // Self-serve plan selection - no payment gateway exists yet, so this is how any authenticated
    // role (including 'user') picks/switches its own plan. Superadmin/master don't need this
    // (unlimited access via isSuperAdminOrMaster) but calling it is harmless.
    public function selectMine(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'subscription_package_id' => ['required', 'exists:subscription_packages,id'],
        ]);

        $user = $request->user();
        $package = SubscriptionPackage::query()->findOrFail($validated['subscription_package_id']);

        $subscription = UserSubscription::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'subscription_package_id' => $package->id,
                'active' => true,
                'started_at' => now(),
                'expires_at' => now()->addMonth(),
                'remaining_tokens' => $package->lena_ai_tokens,
            ]
        );
        $subscription->load('subscriptionPackage');

        return response()->json(['message' => 'Subscription updated successfully.', 'data' => $subscription, 'meta' => [], 'errors' => []]);
    }

    // Admin-only: subscribe/switch a user to a package (one active subscription per user).
    public function store(Request $request, int $userId): JsonResponse
    {
        try {
            $validated = $request->validate([
                'subscription_package_id' => ['required', 'exists:subscription_packages,id'],
                'active' => ['sometimes', 'boolean'],
                'expires_at' => ['nullable', 'date'],
                'remaining_tokens' => ['nullable', 'integer', 'min:0'],
            ]);

            $user = User::query()->findOrFail($userId);
            $package = SubscriptionPackage::query()->findOrFail($validated['subscription_package_id']);

            $subscription = UserSubscription::query()->firstOrNew(['user_id' => $user->id]);
            $subscription->fill([
                'subscription_package_id' => $package->id,
                'active' => $validated['active'] ?? true,
                'expires_at' => $validated['expires_at'] ?? now()->addMonth(),
                'remaining_tokens' => $validated['remaining_tokens'] ?? $package->lena_ai_tokens,
            ]);
            $subscription->started_at ??= now();
            $subscription->save();
            $subscription->load('subscriptionPackage');

            return response()->json(['message' => 'Subscription assigned successfully.', 'data' => $subscription, 'meta' => [], 'errors' => []], 201);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'User or package not found.'], 404);
        } catch (Exception $e) {
            return response()->json(['error' => 'Unable to assign subscription. Please try again.'], 500);
        }
    }

    public function destroy(int $id): JsonResponse
    {
        try {
            UserSubscription::query()->findOrFail($id)->delete();

            return response()->json(['message' => 'Subscription removed successfully.', 'data' => null, 'meta' => [], 'errors' => []]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Subscription not found.'], 404);
        }
    }
}
