<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Bounded schema preparation for the explicitly approved OPS deployment; never seeds business data. */
final class PrepareOpsRelease extends Command
{
    protected $signature = 'freightbook:prepare-ops-release {--deployment-site= : Verified host invoking the deployment script}';
    protected $description = 'Verify the deployment database and apply the two additive OPS migrations';

    private const MIGRATIONS = [
        '2026_10_21_000001_create_operational_compliance',
        '2026_10_22_000001_create_transport_flows',
    ];
    private const COMPLIANCE_TABLES = ['ops_checklist_versions','ops_operational_state','ops_inspection_cases',
        'company_country_profiles','company_capability_verifications','compliance_document_links','compliance_audit'];

    public function handle(): int
    {
        // Inspect effective configuration before making any schema or migration-history write.
        $connection = DB::connection();
        $driver = $connection->getConfig('driver');
        $host = $connection->getConfig('host');
        $port = $connection->getConfig('port');
        $database = $connection->getConfig('database');
        $memory = $driver === 'sqlite' && $database === ':memory:' && $host === null;
        if (!$memory) {
            // The hosting instance still has APP_URL=localhost; use the actual redeploy request
            // host when explicitly supplied, while retaining the same finite site allow-list.
            $site = strtolower((string)($this->option('deployment-site') ?: parse_url((string)config('app.url'),PHP_URL_HOST)));
            if (!in_array($driver,['mysql','mariadb','pgsql'],true)
                || !in_array($host,['localhost','127.0.0.1','::1'],true) || !$database
                || !in_array($site,['freightbook.ai','www.freightbook.ai','cargo.qla.dev'],true)) {
                $this->line('Preflight: '.json_encode(['driver'=>$driver,'host_scope'=>in_array($host,['localhost','127.0.0.1','::1'],true)?'local':'non-local',
                    'app_host'=>parse_url((string)config('app.url'),PHP_URL_HOST),'database_configured'=>(bool)$database]));
                $this->error('Deployment database target is not verified. No schema changes applied.');
                return self::FAILURE;
            }
            $actual = $connection->selectOne($driver === 'pgsql' ? 'SELECT current_database() AS target' : 'SELECT DATABASE() AS target');
            if (($actual->target ?? null) !== $database) {
                $this->error('Effective database differs from configured target. No schema changes applied.');
                return self::FAILURE;
            }
        } elseif (($connection->select('PRAGMA database_list')[0]->file ?? null) !== '') {
            $this->error('SQLite test connection is not in memory.');
            return self::FAILURE;
        }
        // Public redeploy logs contain only a fingerprint, never database names or credentials.
        $this->info('Verified database target: '.$driver.' / '.substr(hash('sha256',json_encode([$driver,$host,$port,$database])),0,16));
        foreach (['companies','documents','ops_orders','ops_order_documents'] as $table) {
            if (!Schema::hasTable($table)) {
                $this->error('Existing application schema is incomplete. No OPS migration applied.');
                return self::FAILURE;
            }
        }
        $migrator = app('migrator');
        if (!$migrator->repositoryExists()) {
            $this->error('Existing migration history is missing. No OPS migration applied.');
            return self::FAILURE;
        }
        $ran = $migrator->getRepository()->getRan();
        if (!in_array(self::MIGRATIONS[0],$ran,true)) {
            foreach (self::COMPLIANCE_TABLES as $table) {
                if (Schema::hasTable($table)) {
                    $this->error('Unregistered or partial compliance schema requires review. No schema changes applied.');
                    return self::FAILURE;
                }
            }
        }
        if (!in_array(self::MIGRATIONS[1],$ran,true)
            && (Schema::hasTable('ops_transport_flows') || Schema::hasColumn('ops_checklist_versions','transport_type') || Schema::hasColumn('ops_checklist_versions','variant'))) {
            $this->error('Unregistered or partial transport schema requires review. No schema changes applied.');
            return self::FAILURE;
        }
        $paths = array_map(fn($migration)=>database_path('migrations/'.$migration.'.php'),self::MIGRATIONS);
        foreach ($paths as $path) {
            if (!is_file($path)) {
                $this->error('Required OPS migration file is missing.');
                return self::FAILURE;
            }
        }
        $migrator->setOutput($this->output)->run($paths,['pretend'=>false,'step'=>true]);
        foreach ([...self::COMPLIANCE_TABLES,'ops_transport_flows'] as $table) {
            if (!Schema::hasTable($table)) {
                $this->error('OPS schema verification failed after migration.');
                return self::FAILURE;
            }
        }
        if (!Schema::hasColumn('ops_checklist_versions','transport_type') || !Schema::hasColumn('ops_checklist_versions','variant')) {
            $this->error('Transport template columns are missing.');
            return self::FAILURE;
        }
        $this->info('OPS schema ready. No business records seeded, reset or deleted.');
        return self::SUCCESS;
    }
}
