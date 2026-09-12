<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EntityResource;
use App\Models\ShipmentWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ShipmentWorkspaceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $records = $this->visibleQuery($request)
            ->with($this->relations())
            ->latest('booked_at')
            ->paginate(max(1, min(100, $request->integer('per_page', 25))));

        return response()->json([
            'message' => 'Shipment workspaces retrieved successfully.',
            'data' => EntityResource::collection($records->items())->resolve($request),
            'meta' => ['current_page' => $records->currentPage(), 'last_page' => $records->lastPage(), 'total' => $records->total()],
            'errors' => [],
        ]);
    }

    public function show(Request $request, ShipmentWorkspace $shipmentWorkspace): JsonResponse
    {
        $record = $this->visibleQuery($request)->with($this->relations())->findOrFail($shipmentWorkspace->id);

        return response()->json(['message' => 'Shipment workspace retrieved successfully.', 'data' => (new EntityResource($record))->resolve($request), 'meta' => [], 'errors' => []]);
    }

    public function update(Request $request, ShipmentWorkspace $shipmentWorkspace): JsonResponse
    {
        $record = $this->visibleQuery($request)->findOrFail($shipmentWorkspace->id);
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(ShipmentWorkspace::STATUSES)],
            'offer_status' => ['sometimes', Rule::in([
                'published', 'open_for_reservations', 'reservation_selected', 'booking_confirmed',
                'preparation', 'ready_for_pickup', 'in_execution', 'completed', 'cancelled', 'expired',
                'pending_customer_approval', 'accepted', 'rejected', 'withdrawn', 'not_selected',
            ])],
            'operational_checklist' => ['sometimes', 'array'],
            'operational_checklist.*.key' => ['required_with:operational_checklist', 'string', 'max:100', 'distinct'],
            'operational_checklist.*.required_for_status' => ['sometimes', 'required', Rule::in(['in_delivery', 'received', 'review', 'finished'])],
            'operational_checklist.*.status' => ['required_with:operational_checklist', 'in:pending,in_progress,completed,blocked'],
            'operational_checklist.*.due_date' => ['nullable', 'date'],
            'operational_checklist.*.action_value' => ['nullable', 'string', 'max:2000'],
            'operational_checklist.*.completed_at' => ['nullable', 'date'],
            'operational_checklist.*.completed_by_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'cancellation_reason' => ['nullable', 'string', 'max:2000', 'required_if:status,cancelled'],
            'additional_charges' => ['sometimes', 'array'],
            'additional_charges.*.type' => ['required', 'string', 'max:120'],
            'additional_charges.*.condition' => ['nullable', 'string', 'max:500'],
            'additional_charges.*.rate' => ['required', 'numeric', 'min:0'],
            'additional_charges.*.unit' => ['nullable', 'string', 'max:30'],
            'approve_additional_charge_id' => ['sometimes', 'string', 'max:100'],
        ]);

        $user = $request->user();
        $isCustomer = (int) $record->customer_user_id === (int) $user->id;
        $isProvider = (int) $record->provider_user_id === (int) $user->id
            || ($record->provider_company_id && $user->companies()->whereKey($record->provider_company_id)->exists());
        $isAdmin = $user->isSuperAdminOrMaster();

        if (array_key_exists('additional_charges', $data)) {
            abort_unless($isProvider || $isAdmin, 403, 'Only the provider can add additional charges.');
            $existing = $record->additional_charges ?? [];
            $newCharges = collect($data['additional_charges'])->map(fn (array $charge) => [
                'id' => (string) \Illuminate\Support\Str::uuid(), 'type' => $charge['type'],
                'condition' => $charge['condition'] ?? '', 'rate' => (float) $charge['rate'],
                'unit' => $charge['unit'] ?? '', 'approved' => false, 'approved_at' => null, 'source' => 'workspace',
            ])->all();
            $data['additional_charges'] = [...$existing, ...$newCharges];
        }
        if (isset($data['approve_additional_charge_id'])) {
            abort_unless($isCustomer || $isAdmin, 403, 'Only the customer can approve an additional charge.');
            $charges = collect($record->additional_charges ?? []);
            abort_unless($charges->contains('id', $data['approve_additional_charge_id']), 422, 'Unknown additional charge.');
            $data['additional_charges'] = $charges->map(function (array $charge) use ($data): array {
                if ($charge['id'] === $data['approve_additional_charge_id'] && ! ($charge['approved'] ?? false)) {
                    $charge['approved'] = true;
                    $charge['approved_at'] = now()->toIso8601String();
                }
                return $charge;
            })->all();
            unset($data['approve_additional_charge_id']);
        }

        if (array_key_exists('offer_status', $data)) {
            abort_unless($isCustomer || $isProvider || $isAdmin, 403, 'Only workspace participants can update the accepted offer status.');
            $record->acceptedOffer()->update(['status' => $data['offer_status']]);
            unset($data['offer_status']);
        }

        if (array_key_exists('operational_checklist', $data)) {
            $isAssignedDriver = (int) $record->freightLoad?->assigned_driver_user_id === (int) $user->id;
            abort_unless($isProvider || $isAdmin || $isAssignedDriver, 403, 'Only the selected provider or assigned driver can update the operational checklist.');
            // Keep every task and enforce fixed categories, including for older clients.
            $existing = collect($record->operational_checklist)->keyBy('key');
            $updates = collect($data['operational_checklist'])->keyBy('key');
            abort_if($updates->keys()->diff($existing->keys())->isNotEmpty(), 422, 'Unknown checklist item.');
            foreach ($updates as $key => $update) {
                $previous = $existing->get($key);
                if ((($update['status'] ?? '') === 'completed' && ($previous['status'] ?? '') !== 'completed')
                    || (array_key_exists('action_value', $update) && $update['action_value'] !== ($previous['action_value'] ?? null))) {
                    \App\Services\ChecklistStatusRequirements::assertTaskAllowed($record->freightLoad, $previous);
                }
            }
            $data['operational_checklist'] = \App\Services\ChecklistStatusRequirements::withCategories(
                $existing->map(fn ($item, $key) => array_merge($item, $updates->get($key, [])))->values()->all()
            );
        }
        if (isset($data['status'])) {
            $allowed = $isAdmin
                || ($isProvider && in_array($data['status'], ['in_execution', 'completed', 'cancelled'], true))
                || ($isCustomer && in_array($data['status'], ['completed', 'cancelled'], true));
            abort_unless($allowed, 403, 'You cannot make this workspace status transition.');
        }

        if (($data['status'] ?? null) === 'cancelled') {
            $data['cancelled_at'] = now();
            $data['cancelled_by_user_id'] = $user->id;
        }

        $record->update($data);
        if (array_key_exists('additional_charges', $data)) {
            // The original bid amount already includes its approved bid lines. Only workspace
            // additions increment the agreed amount after customer approval.
            $increment = collect($data['additional_charges'])->filter(fn (array $charge) => ($charge['source'] ?? '') === 'workspace' && ($charge['approved'] ?? false))->sum('rate');
            $base = (float) $record->acceptedOffer?->amount;
            $record->update(['agreed_amount' => $base + $increment]);
        }
        $record->load($this->relations());

        return response()->json(['message' => 'Shipment workspace updated successfully.', 'data' => (new EntityResource($record))->resolve($request), 'meta' => [], 'errors' => []]);
    }

    private function visibleQuery(Request $request): Builder
    {
        $user = $request->user();
        $query = ShipmentWorkspace::query();
        if ($user->isSuperAdminOrMaster()) return $query;

        $companyIds = $user->companies()->pluck('companies.id');

        return $query->where(function (Builder $visible) use ($user, $companyIds): void {
            $visible->where('customer_user_id', $user->id)
                ->orWhere('provider_user_id', $user->id)
                ->orWhereHas('conversation.participants', fn (Builder $participants) => $participants->where('users.id', $user->id));
            if ($companyIds->isNotEmpty()) $visible->orWhereIn('provider_company_id', $companyIds);
        });
    }

    private function relations(): array
    {
        return [
            'freightLoad.stops', 'shipment', 'acceptedOffer', 'customer', 'providerCompany',
            'providerUser', 'conversation.participants', 'freightLoad.documents',
            'freightLoad.vehicle', 'freightLoad.assignedDriver.driver',
        ];
    }
}
