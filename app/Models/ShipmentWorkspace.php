<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShipmentWorkspace extends BaseModel
{
    protected $table = 'shipment_workspace';

    public const STATUSES = ['booked', 'in_execution', 'completed', 'cancelled'];

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
