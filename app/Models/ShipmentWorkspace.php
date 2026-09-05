<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShipmentWorkspace extends BaseModel
{
    protected $table = 'shipment_workspace';

    public const STATUSES = ['booked', 'in_execution', 'completed', 'cancelled'];

    public function getOperationalChecklistAttribute($value): array
    {
        $items = is_array($value) ? $value : (json_decode($value ?? '[]', true) ?: []);
        $snapshot = $this->load_snapshot ?? [];
        $isStorage = ($snapshot['transport_type'] ?? '') === 'warehouse' || !empty($snapshot['for_storage']);
        // Older storage bookings inherited the road checklist. Adapt untouched lists
        // on read; retain any list with recorded work instead of discarding progress.
        if ($isStorage && collect($items)->contains('key', 'assign_driver_and_vehicle')
            && collect($items)->every(fn ($item) => ($item['status'] ?? 'pending') === 'pending'
                && empty($item['action_value']) && empty($item['completed_at']) && empty($item['due_date']))) {
            return array_map(fn ($key) => [
                'key' => $key, 'status' => 'pending', 'action_value' => null,
                'completed_at' => null, 'completed_by_user_id' => null,
            ], ['confirm_storage_arrival', 'check_storage_documents', 'record_storage_receipt', 'assign_storage_location', 'confirm_storage_dispatch']);
        }

        return $items;
    }

    protected function casts(): array
    {
        return [
            'agreed_amount' => 'decimal:2',
            'load_snapshot' => 'array',
            'offer_snapshot' => 'array',
            'parties_snapshot' => 'array',
            'operational_checklist' => 'array',
            'booked_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function freightLoad(): BelongsTo { return $this->belongsTo(Load::class, 'load_id'); }
    public function shipment(): BelongsTo { return $this->belongsTo(Shipment::class); }
    public function acceptedOffer(): BelongsTo { return $this->belongsTo(Offer::class, 'accepted_offer_id'); }
    public function customer(): BelongsTo { return $this->belongsTo(User::class, 'customer_user_id'); }
    public function providerCompany(): BelongsTo { return $this->belongsTo(Company::class, 'provider_company_id'); }
    public function providerUser(): BelongsTo { return $this->belongsTo(User::class, 'provider_user_id'); }
    public function conversation(): BelongsTo { return $this->belongsTo(Conversation::class); }
}
