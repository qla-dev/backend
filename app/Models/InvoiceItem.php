<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceItem extends BaseModel
{
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function freightLoad(): BelongsTo
    {
        return $this->belongsTo(Load::class, 'load_id');
    }
}
