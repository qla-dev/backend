<?php

namespace App\Services;

use App\Models\Load;
use Illuminate\Validation\ValidationException;

class ChecklistStatusRequirements
{
    public static function waitingForStatus(array $item): ?string
    {
        return ($item['key'] ?? '') === 'proof_of_delivery' ? 'in_delivery' : null;
    }

    public static function assertTaskAllowed(Load $load, array $item): void
    {
        $required = self::waitingForStatus($item);
        if ($required !== null && $load->status !== $required) {
            throw ValidationException::withMessages([
                'waiting_for_status' => 'Proof of delivery can only be submitted while the load is in delivery.',
            ]);
        }
    }

    public static function category(array $item): string
    {
        if (($item['key'] ?? '') === 'vehicle_return') return 'finished';
        return in_array($item['key'] ?? '', ['proof_of_delivery', 'arrival_and_release_documents'], true)
            ? 'received' : 'in_delivery';
    }

    public static function withCategories(array $items): array
    {
        return array_map(fn (array $item): array => array_merge($item, [
            'required_for_status' => self::category($item),
            'waiting_for_status' => self::waitingForStatus($item),
        ]), $items);
    }

    public function assertAllowed(Load $load): void
    {
        if (!in_array($load->status, ['in_delivery', 'received'], true)) return;

        $workspace = $load->shipmentWorkspace;
        $items = $workspace?->operational_checklist ?? [];
        if ($items === []) {
            throw ValidationException::withMessages([
                'status' => 'Complete the shipment workspace checklist before changing this status.',
            ]);
        }

        $pending = array_filter($items, fn (array $item): bool =>
            // Receiving also requires the earlier delivery conditions to remain fulfilled.
            (self::category($item) === 'in_delivery' || ($load->status === 'received' && self::category($item) === 'received'))
            && ($item['status'] ?? 'pending') !== 'completed');
        if ($pending !== []) {
            throw ValidationException::withMessages([
                'status' => 'Complete all checklist items required for this status before changing it.',
                'checklist_items' => array_values(array_map(fn (array $item): string => (string) $item['key'], $pending)),
            ]);
        }
    }
}
