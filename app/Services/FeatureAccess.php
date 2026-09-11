<?php

namespace App\Services;

use App\Models\User;

/**
 * Who may see what, as one table - the server's copy of frontend/src/lib/permissions.ts.
 *
 * The browser's copy decides what to draw; this one decides what is allowed, because a hidden button
 * is not a permission. The two are kept cell-for-cell identical on purpose: when a rule changes it
 * changes in both, and `FeatureAccessTest` asserts the shape so a one-sided edit is caught.
 */
class FeatureAccess
{
    public const FULL = 'full';
    public const VIEW = 'view';
    public const NONE = 'none';

    /**
     * Column order, shared by every row so the table reads as the grid it is.
     *
     * `manager` appears twice: one API role means a logistics manager in a haulier and a warehouse
     * manager in a depot, and they are trusted with different things. The company is what tells them
     * apart - see `permissionRole()`.
     */
    private const ORDER = [
        'superadmin', 'company', 'manager', 'dispatcher', 'warehouse',
        'warehouse_manager', 'user', 'driver', 'forwarder', 'finance',
    ];

    private const F = self::FULL;
    private const V = self::VIEW;
    private const N = self::NONE;

    /** One row per feature, in the column order above. */
    private const MATRIX = [
        //                       SA  company manager dispatch warehouse whMgr customer driver forwarder finance
        'commandCenter'      => [self::F, self::F, self::F, self::F, self::F, self::F, self::V, self::V, self::F, self::N],
        'customers'          => [self::F, self::F, self::F, self::V, self::N, self::N, self::V, self::N, self::F, self::V],
        'logisticsCompanies' => [self::F, self::V, self::V, self::V, self::V, self::V, self::V, self::N, self::F, self::V],
        'warehouseCompanies' => [self::F, self::V, self::V, self::V, self::F, self::F, self::V, self::N, self::F, self::V],
        'carriers'           => [self::F, self::F, self::F, self::F, self::V, self::V, self::N, self::N, self::F, self::V],
        'freightExchange'    => [self::F, self::F, self::F, self::F, self::V, self::V, self::V, self::F, self::F, self::N],
        'globalTracking'     => [self::F, self::F, self::F, self::F, self::F, self::F, self::F, self::F, self::F, self::N],
        'warehouse'          => [self::F, self::F, self::F, self::N, self::F, self::F, self::V, self::N, self::V, self::V],
        'docks'              => [self::F, self::V, self::V, self::N, self::F, self::F, self::N, self::N, self::V, self::N],
        'fleet'              => [self::F, self::F, self::F, self::F, self::N, self::N, self::N, self::N, self::F, self::F],
        'finance'            => [self::F, self::V, self::V, self::N, self::V, self::V, self::V, self::N, self::V, self::F],
        'emailStudio'        => [self::F, self::N, self::N, self::N, self::N, self::N, self::N, self::N, self::N, self::N],
        'documents'          => [self::F, self::F, self::F, self::F, self::F, self::F, self::F, self::F, self::F, self::F],
        'tariffs'            => [self::F, self::F, self::F, self::V, self::F, self::V, self::V, self::N, self::F, self::V],
    ];

    /**
     * Features reached only by operating the thing they are about.
     *
     * Warehouses and fleets are declared in the profile and are self-asserted, so the table applies
     * only once the account has said it operates them AND its company is verified. Warehouse
     * companies are never gated on a warehouse switch - the warehouse is what they are - and the
     * superadmin and finance see the global view because theirs is oversight, not ownership.
     */
    private const OWNED = ['warehouse' => 'warehouse', 'docks' => 'warehouse', 'fleet' => 'fleet'];
    private const OWNERSHIP_GATED = ['company', 'manager', 'dispatcher', 'forwarder'];

    /** The role as the table names it, or null for a user the table does not cover. */
    public static function permissionRole(?User $user): ?string
    {
        $role = $user?->role?->name;

        return match ($role) {
            'superadmin', 'master' => 'superadmin',
            'company' => 'company',
            'manager' => self::isWarehouseCompany($user) ? 'warehouse_manager' : 'manager',
            'dispatcher' => 'dispatcher',
            'warehouse' => 'warehouse',
            'user' => 'user',
            'driver' => 'driver',
            // Špediter. The API calls the role customs_officer; the table calls them a forwarder.
            'customs_officer' => 'forwarder',
            'finance' => 'finance',
            default => null,
        };
    }

    public static function level(?User $user, string $feature): string
    {
        $role = self::permissionRole($user);
        if ($role === null) return self::NONE;

        $index = array_search($role, self::ORDER, true);
        $level = self::MATRIX[$feature][$index] ?? self::NONE;
        if ($level === self::NONE) return self::NONE;

        $owned = self::OWNED[$feature] ?? null;
        if ($owned !== null && in_array($role, self::OWNERSHIP_GATED, true)) {
            $declared = $owned === 'warehouse'
                ? (bool) ($user?->have_warehouse)
                : (bool) ($user?->have_fleet);
            if (! ($declared && self::isVerified($user))) return self::NONE;
        }

        return $level;
    }

    /** Reachable at all - true for full use and for read-only. */
    public static function canAccess(?User $user, string $feature): bool
    {
        return self::level($user, $feature) !== self::NONE;
    }

    /** May change things here, rather than only read them. */
    public static function canEdit(?User $user, string $feature): bool
    {
        return self::level($user, $feature) === self::FULL;
    }

    public static function features(): array
    {
        return array_keys(self::MATRIX);
    }

    private static function isVerified(?User $user): bool
    {
        return (bool) $user?->companies()->whereNotNull('verified_at')->exists();
    }

    private static function isWarehouseCompany(?User $user): bool
    {
        return (bool) $user?->companies()->where('warehouse_first', true)->exists();
    }
}
