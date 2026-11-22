<?php

namespace App\Models;

use App\Models\Concerns\HasReviews;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class Company extends BaseModel
{
    use HasReviews;

    protected static function booted(): void
    {
        static::created(function (Company $company): void {
            if (Schema::hasTable('business_workspaces')) {
                DB::table('business_workspaces')->insert([
                    'id' => $company->id, 'company_id' => $company->id,
                ]);
            }
        });
    }

    protected function casts(): array
    {
        return ['verified_at' => 'datetime', 'warehouse_first' => 'boolean'];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot(['status', 'invited_by_user_id', 'joined_at','departments'])->withTimestamps();
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    public function loads(): HasMany
    {
        return $this->hasMany(Load::class);
    }

    public function warehouses(): HasMany
    {
        return $this->hasMany(Warehouse::class, 'user_id', 'owner_user_id');
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(CompanyInvitation::class);
    }
}
