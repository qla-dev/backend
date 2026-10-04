<?php

namespace App\Console\Commands;

use App\Services\Accounting\PantheonConnector;
use App\Services\Crm\PantheonCrmSync;
use App\Services\Ops\OpsOrders;
use App\Services\Ops\OpsPantheonSync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncPantheon extends Command
{
    protected $signature = 'accounting:pantheon-sync {--company= : Sync only this company, even if its interval has not elapsed}';

    protected $description = 'Sync PANTHEON accounts/partners and push posted journal entries, and pull CRM offers/orders for connectors with sync enabled';

    public function handle(PantheonConnector $pantheon, PantheonCrmSync $crmSync, OpsPantheonSync $opsSync): int
    {
        $company = $this->option('company');
        $due = $company ? array_filter($pantheon->due(true), fn ($c) => (int) $c->company_id === (int) $company) : $pantheon->due();
        foreach ($due as $connector) {
            try {
                // Scheduled runs act as the user who last saved the connector settings.
                $result = $pantheon->sync((int) $connector->company_id, (int) $connector->updated_by);
                $this->info("Company {$connector->company_id}: accounts +{$result['accounts']['created']}/~{$result['accounts']['updated']}, partners +{$result['partners']['created']}/~{$result['partners']['updated']}, entries {$result['entries']['exported']}");
            } catch (\Throwable $e) {
                // The failure is stored on the connector; one company must not stop the others.
                $this->error("Company {$connector->company_id}: sync failed");
            }
            try {
                // CRM offers/orders are read-only; a CRM failure never blocks the accounting sync.
                $crm = $crmSync->pull((int) $connector->company_id, (int) $connector->updated_by);
                $this->info("Company {$connector->company_id}: CRM documents {$crm['documents']} (+{$crm['created']}), contacts {$crm['contacts']}");
            } catch (\Throwable $e) {
                $this->error("Company {$connector->company_id}: CRM pull failed");
            }
            try {
                // Work orders: pushed only when this company enabled the Ops sync (OpsPantheonSync rules apply).
                if (OpsOrders::available() && DB::table('ops_pantheon_sync')->where('company_id', $connector->company_id)->where('sync_enabled', true)->exists()) {
                    $ops = $opsSync->sync((int) $connector->company_id);
                    $this->info("Company {$connector->company_id}: work orders pushed ".count($ops['pushed']).', updated '.count($ops['updated']).', waiting '.count($ops['waiting']));
                }
            } catch (\Throwable $e) {
                $this->error("Company {$connector->company_id}: work order sync failed");
            }
        }

        return self::SUCCESS;
    }
}
