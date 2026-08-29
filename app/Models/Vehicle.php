<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vehicle extends BaseModel
{
    protected function casts(): array
    {
        return ['features' => 'array', 'capacity_kg' => 'decimal:2', 'capacity_m3' => 'decimal:2'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function assignedDriver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_driver_user_id');
    }

    public function permittedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'fleet_access')->withPivot(['can_view', 'can_dispatch', 'can_edit', 'granted_by_user_id'])->withTimestamps();
    }

    public function locations(): HasMany
    {
        return $this->hasMany(VehicleLocation::class);
    }

    public function loads(): HasMany
    {
        return $this->hasMany(Load::class);
    }

    public function returnInspections(): HasMany
    {
        return $this->hasMany(VehicleReturnInspection::class)->latest('inspected_at');
    }
}
