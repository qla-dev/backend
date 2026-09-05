<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Load;
use App\Models\Message;
use App\Models\Offer;
use App\Models\ShipmentWorkspace;
use App\Models\User;
use Illuminate\Support\Str;

class ShipmentWorkspaceCreator
{
    public function create(Load $load, Offer $offer, User $actor): ShipmentWorkspace
    {
        $load->loadMissing(['stops', 'customer.customerProfile', 'shipment', 'company.owner']);
        $offer->loadMissing(['company.owner', 'creator', 'driver', 'vehicle']);

        $providerUserId = $offer->company?->owner_user_id ?: $offer->created_by_user_id;
        $reference = $this->reference();
        $conversation = Conversation::query()->create([
            'company_id' => $offer->company_id,
            'load_id' => $load->id,
            'created_by_user_id' => $actor->id,
            'channel' => 'inapp',
            'subject' => "Shipment {$reference}",
            'last_message_at' => now(),
        ]);

        $participantIds = collect([
            $load->customer_user_id,
            $providerUserId,
            $offer->created_by_user_id,
            $offer->driver_user_id,
        ])->filter()->unique()->values()->all();
        $conversation->participants()->sync($participantIds);

        $workspace = ShipmentWorkspace::query()->create([
            'reference' => $reference,
            'load_id' => $load->id,
            'shipment_id' => $load->shipment?->id,
            'accepted_offer_id' => $offer->id,
            'customer_user_id' => $load->customer_user_id,
            'provider_company_id' => $offer->company_id,
            'provider_user_id' => $providerUserId,
            'conversation_id' => $conversation->id,
            'status' => 'booked',
            'currency' => strtoupper((string) $offer->currency),
            'agreed_amount' => $offer->amount,
            'load_snapshot' => $this->loadSnapshot($load),
            'offer_snapshot' => $this->offerSnapshot($offer),
            'parties_snapshot' => $this->partiesSnapshot($load, $offer),
            'operational_checklist' => $this->checklist((string) $load->transport_type),
            'booked_at' => now(),
        ]);

        Message::query()->create([
            'conversation_id' => $conversation->id,
            'sender_user_id' => $actor->id,
            'body' => "Booking confirmed. Shipment Workspace {$reference} was created.",
            'sent_at' => now(),
        ]);

        return $workspace;
    }

    private function reference(): string
    {
        do {
            $reference = Str::upper(Str::random(6));
        } while (ShipmentWorkspace::query()->where('reference', $reference)->exists()
            || Load::query()->where('booking_reference', $reference)->exists());

        return $reference;
    }

    private function loadSnapshot(Load $load): array
    {
        return [
            'id' => $load->id,
            'public_id' => $load->public_id,
            'title' => $load->title,
            'transport_type' => $load->transport_type,
            'cargo_type' => $load->cargo_type,
            'goods_type' => $load->goods_type,
            'weight_kg' => $load->weight_kg,
            'dimensions' => ['length_m' => $load->length_m, 'width_m' => $load->width_m, 'height_m' => $load->height_m],
            'stops' => $load->stops->map->only(['type', 'position', 'city', 'country_code', 'address', 'window_starts_at', 'window_ends_at'])->values()->all(),
            'booking_reference' => $load->booking_reference,
            'shipment_reference' => $load->shipment?->tracking_number,
        ];
    }

    private function offerSnapshot(Offer $offer): array
    {
        return $offer->only([
            'id', 'request_type', 'company_id', 'driver_user_id', 'vehicle_id', 'amount', 'currency',
            'price_basis', 'vat', 'payment_terms', 'included_charges', 'excluded_charges',
            'additional_charges', 'equipment_type', 'available_date', 'exact_loading_date',
            'estimated_delivery_date', 'estimated_transit_days', 'can_perform_as_required',
            'has_exceptions', 'message',
        ]);
    }

    private function partiesSnapshot(Load $load, Offer $offer): array
    {
        return [
            'customer' => $load->customer?->only(['id', 'name', 'email', 'phone']),
            'provider' => $offer->company
                ? $offer->company->only(['id', 'name', 'email', 'phone', 'country_code', 'city'])
                : $offer->creator?->only(['id', 'name', 'email', 'phone']),
            'provider_contact' => $offer->creator?->only(['id', 'name', 'email', 'phone']),
            'driver' => $offer->driver?->only(['id', 'name', 'email', 'phone']),
            'vehicle' => $offer->vehicle?->only(['id', 'registration_number', 'vehicle_type', 'make', 'model']),
        ];
    }

    private function checklist(string $transportType): array
    {
        $items = match ($transportType) {
            'sea' => ['booking_confirmation', 'shipping_line_and_agent', 'vessel_and_voyage', 'container_details', 'shipping_instructions', 'vgm', 'draft_bill_of_lading', 'approve_draft', 'final_bill_of_lading', 'terminal_and_cutoff'],
            'air' => ['airline_and_agent', 'flight_details', 'mawb_hawb', 'cargo_acceptance', 'security_and_customs_documents', 'draft_awb', 'approve_awb', 'departure_status', 'arrival_status'],
            'rail' => ['rail_operator', 'terminals', 'wagon_or_container', 'rail_booking_confirmation', 'departure_schedule', 'transit_status', 'arrival_and_release_documents'],
            default => ['assign_driver_and_vehicle', 'confirm_pickup_time', 'vehicle_registrations', 'cmr_and_documents', 'confirm_pickup', 'tracking_and_status_updates', 'proof_of_delivery'],
        };

        return array_map(fn (string $key): array => [
            'key' => $key,
            'status' => 'pending',
            'action_value' => null,
            'completed_at' => null,
            'completed_by_user_id' => null,
        ], $items);
    }
}
