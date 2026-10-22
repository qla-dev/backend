<?php
namespace Tests\Unit;

use App\Console\Commands\PrepareOpsRelease;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OpsDeploymentTest extends TransportFlowTest
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->make(\Illuminate\Contracts\Console\Kernel::class)->registerCommand(new PrepareOpsRelease());
        Schema::create('migrations',function(Blueprint $t){$t->id();$t->string('migration');$t->integer('batch');});
    }

    public function test_release_additive_migrations_run_and_retry_without_changing_business_records(): void
    {
        foreach(['ops_transport_flows','ops_operational_state','ops_inspection_cases','company_country_profiles','company_capability_verifications','compliance_document_links','compliance_audit','ops_checklist_versions'] as $table) Schema::drop($table);
        $before=['companies'=>DB::table('companies')->count(),'documents'=>DB::table('documents')->count(),'invoices'=>DB::table('invoices')->count(),'entries'=>DB::table('accounting_entries')->count()];
        self::assertSame(0,Artisan::call('freightbook:prepare-ops-release'));
        self::assertSame(3,DB::table('migrations')->count());
        self::assertTrue(Schema::hasTable('ops_transport_flows'));
        self::assertTrue(Schema::hasColumn('ops_checklist_versions','variant'));
        self::assertSame(0,Artisan::call('freightbook:prepare-ops-release'));
        self::assertSame(3,DB::table('migrations')->count());
        self::assertSame($before,['companies'=>DB::table('companies')->count(),'documents'=>DB::table('documents')->count(),'invoices'=>DB::table('invoices')->count(),'entries'=>DB::table('accounting_entries')->count()]);
    }

    public function test_release_unregistered_schema_is_rejected_without_any_write(): void
    {
        self::assertSame(1,Artisan::call('freightbook:prepare-ops-release'));
        self::assertSame(0,DB::table('migrations')->count());
        self::assertStringContainsString('partial compliance schema',Artisan::output());
    }

    public function test_release_verified_completed_schema_is_an_idempotent_no_op(): void
    {
        DB::table('migrations')->insert([
            ['migration'=>'2026_10_21_000001_create_operational_compliance','batch'=>1],
            ['migration'=>'2026_10_22_000001_create_transport_flows','batch'=>2],
        ]);
        self::assertSame(0,Artisan::call('freightbook:prepare-ops-release'));
        self::assertSame(3,DB::table('migrations')->count());
        self::assertStringContainsString('OPS schema ready',Artisan::output());
    }
}
