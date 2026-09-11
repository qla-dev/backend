<?php

namespace App\Services;

use App\Models\Load;
use Illuminate\Validation\ValidationException;

class ChecklistStatusRequirements
{
    /**
     * The delivery ladder, in order. A status requires every checklist category up to and including
     * its own, so receiving carries the departure conditions forward and reviewing carries both.
     */
    public const ORDER = ['in_delivery', 'received', 'review', 'finished'];

    /**
     * Statuses that the checklist gate applies to. `finished` is ordered above them for the
     * cumulative comparison but is not gated here - the load controller owns that transition.
     */
    private const GATED = ['in_delivery', 'received', 'review'];

    /**
     * Tasks that can only be filled in during part of the trip. Proof of delivery is the driver's
     * own evidence of the handover, so it is accepted from departure until the recipient reviews -
     * which includes `received`, the status the driver sets themselves on finishing the drive.
     */
    private const TASK_WINDOWS = [
        'proof_of_delivery' => ['in_delivery', 'received'],
    ];

    /** The statuses a task may be completed in, or null when it may be completed at any time. */
    public static function allowedStatuses(array $item): ?array
    {
        return self::TASK_WINDOWS[$item['key'] ?? ''] ?? null;
    }

    /** The earliest status a restricted task opens at - what clients show while it is still closed. */
    public static function waitingForStatus(array $item): ?string
    {
        return self::allowedStatuses($item)[0] ?? null;
    }

    public static function assertTaskAllowed(Load $load, array $item): void
    {
        $allowed = self::allowedStatuses($item);
        if ($allowed !== null && ! in_array($load->status, $allowed, true)) {
            throw ValidationException::withMessages([
                'waiting_for_status' => 'Proof of delivery can only be submitted while the load is in delivery or received.',
            ]);
        }
    }

    public static function category(array $item): string
    {
        if (($item['key'] ?? '') === 'vehicle_return') return 'finished';

        // The handover documents are what the recipient reviews, so they gate `review` rather than
        // `received` - the carrier marks that one on arrival, before the paperwork is filed.
        return in_array($item['key'] ?? '', ['proof_of_delivery', 'arrival_and_release_documents'], true)
            ? 'review' : 'in_delivery';
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
        if (! in_array($load->status, self::GATED, true)) return;
        $reached = array_search($load->status, self::ORDER, true);

        $workspace = $load->shipmentWorkspace;
        $items = $workspace?->operational_checklist ?? [];
        if ($items === []) {
            throw ValidationException::withMessages([
                'status' => 'Complete the shipment workspace checklist before changing this status.',
            ]);
        }

        $pending = array_filter($items, fn (array $item): bool =>
            array_search(self::category($item), self::ORDER, true) <= $reached
            && ($item['status'] ?? 'pending') !== 'completed');
        if ($pending !== []) {
            throw ValidationException::withMessages([
                'status' => 'Complete all checklist items required for this status before changing it.',
                'checklist_items' => array_values(array_map(fn (array $item): string => (string) $item['key'], $pending)),
            ]);
        }
    }
}
