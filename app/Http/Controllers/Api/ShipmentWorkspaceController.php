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
            'operational_checklist.*.key' => ['required_with:operational_checklist', 'string', 'max:100'],
            'operational_checklist.*.status' => ['required_with:operational_checklist', 'in:pending,in_progress,completed,blocked'],
            'operational_checklist.*.due_date' => ['nullable', 'date'],
            'operational_checklist.*.completed_at' => ['nullable', 'date'],
            'operational_checklist.*.completed_by_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'cancellation_reason' => ['nullable', 'string', 'max:2000', 'required_if:status,cancelled'],
        ]);

        $user = $request->user();
        $isCustomer = (int) $record->customer_user_id === (int) $user->id;
        $isProvider = (int) $record->provider_user_id === (int) $user->id
            || ($record->provider_company_id && $user->companies()->whereKey($record->provider_company_id)->exists());
        $isAdmin = $user->isSuperAdminOrMaster();

        if (array_key_exists('offer_status', $data)) {
            abort_unless($isCustomer || $isProvider || $isAdmin, 403, 'Only workspace participants can update the accepted offer status.');
            $record->acceptedOffer()->update(['status' => $data['offer_status']]);
            unset($data['offer_status']);
        }

        if (array_key_exists('operational_checklist', $data)) {
            abort_unless($isProvider || $isAdmin, 403, 'Only the selected provider can update the operational checklist.');
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
        ];
    }
}
