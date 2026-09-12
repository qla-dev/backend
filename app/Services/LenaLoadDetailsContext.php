<?php

namespace App\Services;

use App\Models\Load;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

/** Read-only context from the same records used by load details. */
class LenaLoadDetailsContext
{
    public function forUser(Load $load, User $user): string
    {
        // Reload, never reuse relations from an earlier answer or a booking snapshot.
        $load->load(['stops', 'shipment.events', 'shipmentWorkspace', 'company', 'consignee']);
        $workspace = $load->shipmentWorkspace;
        $companyIds = $user->companies()->pluck('companies.id')->map(fn ($id) => (int) $id)->all();
        $canReadOperations = $user->isSuperAdminOrMaster()
            || (int) $load->customer_user_id === (int) $user->id
            || (int) $load->assigned_driver_user_id === (int) $user->id
            || in_array((int) $load->company_id, $companyIds, true)
            || ($workspace && (
                (int) $workspace->customer_user_id === (int) $user->id
                || (int) $workspace->provider_user_id === (int) $user->id
                || in_array((int) $workspace->provider_company_id, $companyIds, true)
                || $workspace->conversation()->whereHas('participants', fn ($query) => $query->where('users.id', $user->id))->exists()
            ));

        abort_unless($canReadOperations || $load->status === 'posted', 403, 'This load is no longer available to you.');

        if ($canReadOperations) {
            $load->load([
                'notes' => fn ($query) => $query->where(fn ($visible) => $visible
                    ->where('is_private', false)->orWhereNull('is_private')->orWhere('author_user_id', $user->id))
                    ->with('author')->orderByDesc('updated_at'),
                'documents' => fn ($query) => $query->with('uploader')->orderByDesc('created_at'),
                'assignedDriver', 'vehicle.latestLocation', 'customer',
                'shipmentWorkspace.acceptedOffer.warehouse', 'shipmentWorkspace.acceptedOffer.creator', 'routes.stops',
            ]);
            $vehicle = $load->vehicle;
            $role = $user->role?->name;
            $canReadVehicleReturn = $user->isSuperAdminOrMaster() || ($vehicle && (
                (in_array($role, ['company', 'manager', 'dispatcher', 'customs_officer'], true)
                    && in_array((int) $vehicle->company_id, $companyIds, true))
                || ($role === 'driver' && (
                    (int) $vehicle->owner_user_id === (int) $user->id
                    || (int) $vehicle->assigned_driver_user_id === (int) $user->id
                    || $vehicle->permittedUsers()->whereKey($user->id)->exists()
                    || $vehicle->loads()->where('assigned_driver_user_id', $user->id)->exists()
                ))
            ));
            if ($canReadVehicleReturn) {
                $load->load('vehicleReturnInspection');
            } else {
                $load->setRelation('vehicleReturnInspection', null);
            }
        }

        $context = $this->snapshot($load, (int) $user->id, $canReadOperations);
        if ($canReadOperations) {
            $ownerIds = $user->companies()->pluck('companies.owner_user_id')->push($user->id)->unique();
            $movements = $load->warehouseMovements();
            if (! $user->isSuperAdminOrMaster()) {
                $movements->whereHas('warehouse', fn ($query) => $query->whereIn('user_id', $ownerIds));
            }
            $context['warehouse_movements'] = $movements->orderBy('scheduled_at')->get()->map(fn ($movement) => $this->fields($movement, [
                'direction', 'status', 'scheduled_at', 'completed_at', 'dock_number', 'customer_name',
                'storage_type', 'pallets', 'cbm', 'weight_kg', 'rate', 'currency', 'description',
            ]))->all();
            // The operational thread has its own participant boundary, even for a load owner.
            $thread = $workspace?->conversation()->where(fn ($query) => $query
                ->where('created_by_user_id', $user->id)
                ->orWhereHas('participants', fn ($participants) => $participants->where('users.id', $user->id)))
                ->with('messages.sender')->first();
            $context['shipment_messages'] = $thread ? $thread->messages->map(fn ($message) => [
                ...$this->fields($message, ['id', 'body', 'sent_at', 'edited_at']),
                'sender' => $this->fields($message->sender, ['id', 'name']),
            ])->all() : null;
        }

        return json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** Explicit relation projections avoid serializing credentials, file paths or unrelated records. */
    public function snapshot(Load $load, int $viewerId, bool $canReadOperations): array
    {
        $context = [
            'load' => $load->attributesToArray(),
            'stops' => $load->stops->map(fn ($stop) => $this->fields($stop, [
                'id', 'type', 'position', 'place_type', 'city', 'country_code', 'address', 'latitude', 'longitude',
                'window_starts_at', 'window_ends_at', 'arrived_at', 'completed_at',
            ]))->all(),
            'operational_access' => $canReadOperations ? 'available' : 'not_authorized',
        ];
        if (! $canReadOperations) {
            return $context;
        }

        $workspace = $load->shipmentWorkspace;
        $context['workspace'] = $this->fields($workspace, [
            'id', 'status', 'agreed_amount', 'currency', 'booked_at', 'cancelled_at', 'cancellation_reason',
            'operational_checklist',
        ]);
        if ($context['workspace'] !== null) {
            $context['workspace']['operational_checklist'] = array_map(fn ($item) => [
                ...$item,
                'responsible_party' => in_array($item['key'] ?? '', ['shipping_instructions', 'approve_draft', 'approve_awb'], true)
                    ? 'customer' : 'provider',
            ], $workspace->operational_checklist);
        }
        $context['accepted_offer'] = $this->fields($workspace?->acceptedOffer, [
            'id', 'status', 'amount', 'currency', 'payment_terms', 'estimated_transit_days', 'valid_until',
            'price_basis', 'vat', 'equipment_type', 'request_type', 'message',
            'included_charges', 'excluded_charges', 'additional_charges', 'available_date',
            'exact_loading_date', 'estimated_delivery_date', 'can_perform_as_required', 'has_exceptions',
            'exceptions', 'comment', 'notes', 'available_from', 'available_capacity', 'price_breakdown',
            'services_included', 'optional_conditions',
        ]);
        $context['warehouse'] = $this->fields($workspace?->acceptedOffer?->warehouse, [
            'id', 'name', 'type', 'city', 'country_code', 'address', 'latitude', 'longitude',
        ]);
        $context['parties'] = [
            'customer' => $this->fields($load->customer, ['id', 'name', 'email', 'phone']),
            'consignee' => $this->fields($load->consignee, ['id', 'name', 'company_name', 'email', 'phone', 'address', 'city', 'country_code']),
            'company' => $this->fields($load->company, ['id', 'name', 'email', 'phone', 'address', 'city', 'country_code']),
            'driver' => $this->fields($load->assignedDriver, ['id', 'name', 'email', 'phone']),
            'provider_contact' => $this->fields($workspace?->acceptedOffer?->creator, ['id', 'name', 'email', 'phone']),
        ];
        // These are labelled historical: the overview displays the original parties as well.
        $context['booking_parties_snapshot'] = collect($workspace?->parties_snapshot ?? [])
            ->only(['customer', 'provider', 'provider_contact', 'driver', 'vehicle'])
            ->map(fn ($party) => is_array($party) ? Arr::only($party, [
                'id', 'name', 'email', 'phone', 'country_code', 'city', 'registration_number', 'vehicle_type', 'make', 'model',
            ]) : null)->all();
        $context['vehicle'] = $this->fields($load->vehicle, [
            'id', 'name', 'vehicle_type', 'make', 'model', 'license_plate', 'registration_number', 'status',
            'capacity_kg', 'capacity_m3', 'features',
        ]);
        $context['latest_vehicle_location'] = $this->fields($load->vehicle?->latestLocation, [
            'latitude', 'longitude', 'speed_kmh', 'heading', 'recorded_at',
        ]);
        $context['shipment'] = $this->fields($load->shipment, [
            'id', 'tracking_number', 'status', 'carrier', 'current_latitude', 'current_longitude',
            'estimated_delivery_at', 'delivered_at', 'updated_at',
        ]);
        $context['tracking_events'] = ($load->shipment?->events ?? collect())->map(fn ($event) => $this->fields($event, [
            'status', 'title', 'description', 'location', 'latitude', 'longitude', 'occurred_at',
        ]))->all();
        // A load carries both a free-text `notes` column and a `notes()` relation of LoadNote rows,
        // and the column shadows the relation on the model - so the note rows have to be taken from
        // the loaded relation by name rather than read off the model as a property.
        $context['notes'] = ($load->relationLoaded('notes') ? $load->getRelation('notes') : collect())
            ->filter(fn ($note) => ! $note->is_private || (int) $note->author_user_id === $viewerId)
            ->map(fn ($note) => [
                ...$this->fields($note, ['id', 'note_type', 'priority', 'body', 'is_private', 'created_at', 'updated_at']),
                'author' => $this->fields($note->author, ['id', 'name']),
            ])->values()->all();
        $context['documents'] = $load->documents->map(fn ($document) => [
            ...$this->fields($document, [
                'id', 'name', 'type', 'reference', 'comment', 'mime_type', 'size_bytes', 'expires_at', 'created_at', 'updated_at',
            ]),
            'uploader' => $document->relationLoaded('uploader') ? $this->fields($document->uploader, ['id', 'name']) : null,
        ])->all();
        $context['document_content_available'] = false;
        $context['routes'] = $load->routes->map(fn ($route) => [
            ...$this->fields($route, ['id', 'route_code', 'status', 'distance_km', 'duration_minutes', 'fuel_liters', 'estimated_cost', 'starts_at', 'ends_at']),
            'stops' => $route->stops->map(fn ($stop) => $this->fields($stop, [
                'position', 'name', 'latitude', 'longitude', 'estimated_at', 'arrived_at', 'note',
            ]))->all(),
        ])->all();
        $context['vehicle_return'] = $this->fields($load->vehicleReturnInspection, [
            'mileage_km', 'fuel_level_percent', 'has_damage', 'damage_notes', 'parking_location', 'inspected_at',
        ]);

        return $context;
    }

    private function fields(?Model $model, array $fields): ?array
    {
        return $model ? Arr::only($model->attributesToArray(), $fields) : null;
    }
}
