<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FleetAccess extends BaseModel
{
    protected $table = 'fleet_access';

    protected function casts(): array
    {
        return ['can_view' => 'boolean', 'can_dispatch' => 'boolean', 'can_edit' => 'boolean'];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by_user_id');
    }
}
