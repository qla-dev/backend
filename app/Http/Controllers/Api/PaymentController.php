<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\SubscriptionPackage;
use App\Models\UserSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentController extends Controller
{
    // KM per LenaAI message for a free-amount top-up. Deliberately not exposed to the frontend as
    // a visible rate - the checkout screen only takes a KM amount, this is the only place the
    // conversion happens.
    private const TOKEN_PRICE_BAM = 0.05;

    public function index(Request $request): JsonResponse
    {
        $payments = Payment::query()
            ->where('user_id', $request->user()->id)
            ->with('subscriptionPackage')
            ->latest('id')
            ->get();

        return response()->json(['message' => 'Payment history retrieved.', 'data' => $payments, 'meta' => [], 'errors' => []]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['required_without:subscription_package_id', 'nullable', 'numeric', 'min:1', 'max:100000'],
            'subscription_package_id' => ['required_without:amount', 'nullable', 'integer', 'exists:subscription_packages,id'],
        ]);

        if (filled($validated['subscription_package_id'] ?? null) && filled($validated['amount'] ?? null)) {
            throw ValidationException::withMessages(['amount' => 'Provide either an amount or a package, not both.']);
        }

        $user = $request->user();

        $payment = DB::transaction(function () use ($validated, $user) {
            if (filled($validated['subscription_package_id'] ?? null)) {
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

                return Payment::query()->create([
                    'user_id' => $user->id,
                    'subscription_package_id' => $package->id,
                    'type' => 'package',
                    'amount' => $package->price_monthly,
                    'currency' => $package->currency,
                    'tokens' => $package->lena_ai_tokens,
                    'status' => 'completed',
                ]);
            }

            $amount = (float) $validated['amount'];
            $tokens = (int) floor($amount / self::TOKEN_PRICE_BAM);

            $subscription = UserSubscription::query()->where('user_id', $user->id)->first();
            if (! $subscription) {
                // A top-up needs somewhere to land - give a brand new buyer the entry-level plan as
                // a base bucket, then credit the purchased tokens on top of it below.
                $starter = SubscriptionPackage::query()->where('slug', 'starter')->first();
                $subscription = UserSubscription::query()->create([
                    'user_id' => $user->id,
                    'subscription_package_id' => $starter?->id ?? SubscriptionPackage::query()->orderBy('sort_order')->value('id'),
                    'active' => true,
                    'started_at' => now(),
                    'expires_at' => now()->addMonth(),
                    'remaining_tokens' => $starter?->lena_ai_tokens ?? 0,
                ]);
            }
            $subscription->incrementTokens($tokens);

            return Payment::query()->create([
                'user_id' => $user->id,
                'subscription_package_id' => null,
                'type' => 'topup',
                'amount' => $amount,
                'currency' => 'BAM',
                'tokens' => $tokens,
                'status' => 'completed',
            ]);
        });

        $payment->load('subscriptionPackage');

        return response()->json(['message' => 'Payment completed.', 'data' => $payment, 'meta' => [], 'errors' => []], 201);
    }
}
