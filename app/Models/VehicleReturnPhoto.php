<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehicleReturnPhoto extends BaseModel
{
    public function inspection(): BelongsTo
    {
        return $this->belongsTo(VehicleReturnInspection::class, 'vehicle_return_inspection_id');
    }
}
