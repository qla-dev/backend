<?php

namespace Tests\Unit;

use App\Services\FeatureAccess;
use PHPUnit\Framework\TestCase;

/**
 * The server's copy of the access table, checked against the same expectations the browser's copy is.
 *
 * Everything here avoids the ownership-gated features (warehouse, docks, fleet), because those read
 * the user's companies from the database; `FeatureAccessApiTest` covers those against real records.
 */
class FeatureAccessTest extends TestCase
{
    /** A user stub with a role and no database behind it. */
    private function user(string $role): object
    {
        return new class($role) {
            public function __construct(public string $roleName) {}
            public function __get(string $name): mixed
            {
                return $name === 'role' ? (object) ['name' => $this->roleName] : null;
            }
        };
    }

    private function level(string $role, string $feature): string
    {
        // The service only ever reads ->role->name for these features, so the stub is enough.
        return FeatureAccess::level($this->user($role), $feature);
    }

    public function test_api_roles_map_onto_the_roles_the_table_names(): void
    {
        $this->assertSame('superadmin', FeatureAccess::permissionRole($this->user('superadmin')));
        $this->assertSame('superadmin', FeatureAccess::permissionRole($this->user('master')));
        // Špediter in the table, customs_officer in the API.
        $this->assertSame('forwarder', FeatureAccess::permissionRole($this->user('customs_officer')));
        $this->assertNull(FeatureAccess::permissionRole(null));
    }

    public function test_superadmin_has_every_feature(): void
    {
        foreach (FeatureAccess::features() as $feature) {
            // Ownership-gated rows read the database, so only the ungated ones are asserted here.
            if (in_array($feature, ['warehouse', 'docks', 'fleet'], true)) continue;
            $this->assertSame(FeatureAccess::FULL, $this->level('superadmin', $feature), $feature);
        }
    }

    public function test_email_studio_belongs_to_the_superadmin_alone(): void
    {
        foreach (['company', 'manager', 'dispatcher', 'warehouse', 'user', 'driver', 'customs_officer', 'finance'] as $role) {
            $this->assertSame(FeatureAccess::NONE, $this->level($role, 'emailStudio'), $role);
        }
    }

    public function test_every_role_keeps_its_documents(): void
    {
        foreach (['superadmin', 'company', 'manager', 'dispatcher', 'warehouse', 'user', 'driver', 'customs_officer', 'finance'] as $role) {
            $this->assertSame(FeatureAccess::FULL, $this->level($role, 'documents'), $role);
        }
    }

    public function test_finance_is_written_by_finance_and_only_read_by_a_company(): void
    {
        $this->assertSame(FeatureAccess::FULL, $this->level('finance', 'finance'));
        $this->assertSame(FeatureAccess::VIEW, $this->level('company', 'finance'));
        $this->assertSame(FeatureAccess::VIEW, $this->level('manager', 'finance'));
        $this->assertSame(FeatureAccess::NONE, $this->level('dispatcher', 'finance'));
        $this->assertSame(FeatureAccess::NONE, $this->level('driver', 'finance'));
        $this->assertTrue(FeatureAccess::canEdit($this->user('finance'), 'finance'));
        $this->assertFalse(FeatureAccess::canEdit($this->user('company'), 'finance'));
        $this->assertTrue(FeatureAccess::canAccess($this->user('company'), 'finance'));
    }

    public function test_a_driver_carries_loads_and_nothing_administrative(): void
    {
        $this->assertSame(FeatureAccess::FULL, $this->level('driver', 'freightExchange'));
        $this->assertSame(FeatureAccess::FULL, $this->level('driver', 'globalTracking'));
        $this->assertSame(FeatureAccess::VIEW, $this->level('driver', 'commandCenter'));
        foreach (['customers', 'carriers', 'finance', 'tariffs'] as $feature) {
            $this->assertSame(FeatureAccess::NONE, $this->level('driver', $feature), $feature);
        }
    }

    public function test_a_customer_reads_rather_than_acts(): void
    {
        $this->assertSame(FeatureAccess::FULL, $this->level('user', 'globalTracking'));
        $this->assertSame(FeatureAccess::VIEW, $this->level('user', 'freightExchange'));
        $this->assertSame(FeatureAccess::VIEW, $this->level('user', 'finance'));
        $this->assertSame(FeatureAccess::NONE, $this->level('user', 'carriers'));
    }

    public function test_finance_stays_out_of_operations(): void
    {
        $this->assertSame(FeatureAccess::NONE, $this->level('finance', 'commandCenter'));
        $this->assertSame(FeatureAccess::NONE, $this->level('finance', 'freightExchange'));
        $this->assertSame(FeatureAccess::NONE, $this->level('finance', 'globalTracking'));
    }

    public function test_an_unknown_role_has_nothing(): void
    {
        foreach (FeatureAccess::features() as $feature) {
            $this->assertSame(FeatureAccess::NONE, FeatureAccess::level(null, $feature), $feature);
        }
    }
}
