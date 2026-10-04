<?php

namespace App\Console\Commands;

use App\Services\Accounting\PantheonConnector;
use Illuminate\Console\Command;

class SyncPantheon extends Command
{
    protected $signature = 'accounting:pantheon-sync {--company= : Sync only this company, even if its interval has not elapsed}';
    protected $description = 'Sync PANTHEON accounts/partners and push posted journal entries for connectors with sync enabled';

    public function handle(PantheonConnector $pantheon): int
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
        }

        return self::SUCCESS;
    }
}
