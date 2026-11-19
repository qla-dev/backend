<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Finite, additive release preparation for the user-approved fleet deployment. */
final class PrepareFleetRelease extends Command
{
    protected $signature='freightbook:prepare-fleet-release {--deployment-site=} {--inspect : Read-only database and migration preflight}';
    protected $description='Verify the database and prepare the approved fleet schema without reset or seed';
    private const MIGRATIONS=['2026_11_12_000001_create_fleet_operations','2026_11_17_000001_add_fleet_depots_and_access','2026_11_19_000001_create_fleet_vehicle_couplings','2026_11_19_000002_create_freightbook_workflow_records'];
    private const BASE_TABLES=['fleet_handovers','fleet_assignments','fleet_damages','fleet_files','fleet_tours','fleet_events','fleet_notifications','fleet_import_profiles'];

    public function handle(): int
    {
        $c=DB::connection();$driver=$c->getConfig('driver');$host=$c->getConfig('host');$port=$c->getConfig('port');$database=$c->getConfig('database');
        $memory=$driver==='sqlite'&&$database===':memory:'&&$host===null;
        $site=strtolower((string)($this->option('deployment-site')?:parse_url((string)config('app.url'),PHP_URL_HOST)));
        if(!$memory) {
            if(!in_array($driver,['mysql','mariadb','pgsql'],true)||!in_array($host,['localhost','127.0.0.1','::1'],true)||!$database||!in_array($site,['freightbook.ai','www.freightbook.ai','cargo.qla.dev'],true)) {
                $this->error('Fleet database target is not verified. No schema changes applied.');return self::FAILURE;
            }
            $actual=$c->selectOne($driver==='pgsql'?'SELECT current_database() AS target':'SELECT DATABASE() AS target');
            if(($actual->target??null)!==$database){$this->error('Effective database mismatch.');return self::FAILURE;}
        }elseif(($c->select('PRAGMA database_list')[0]->file??null)!=='') { $this->error('SQLite is not isolated in memory.');return self::FAILURE; }
        $this->info('Verified fleet database: '.$driver.' / '.substr(hash('sha256',json_encode([$driver,$host,$port,$database])),0,16));
        foreach(['companies','users','roles','company_user','vehicles','loads','documents','vehicle_return_inspections'] as $table) {
            if(!Schema::hasTable($table)){$this->error('Fleet prerequisite missing: '.$table);return self::FAILURE;}
        }
        $m=app('migrator');if(!$m->repositoryExists()){$this->error('Migration history is missing.');return self::FAILURE;}
        $ran=$m->getRepository()->getRan();
        foreach(self::MIGRATIONS as $name)$this->line($name.': '.(in_array($name,$ran,true)?'applied':'pending'));
        $first=in_array(self::MIGRATIONS[0],$ran,true);$second=in_array(self::MIGRATIONS[1],$ran,true);
        if(Schema::hasTable('fleet_vehicle_couplings')!==in_array(self::MIGRATIONS[2],$ran,true)){$this->error('Partial/unregistered vehicle coupling schema.');return self::FAILURE;}
        foreach(['fleet_handover_requests','fleet_vehicle_reviews','fleet_vehicle_tasks','crm_logistics_cases','crm_supplier_quotes','crm_quote_versions'] as $table)if(Schema::hasTable($table)!==in_array(self::MIGRATIONS[3],$ran,true)){$this->error('Partial/unregistered workflow schema: '.$table);return self::FAILURE;}
        if(!Schema::hasTable('crm_documents')){$this->error('CRM prerequisite missing.');return self::FAILURE;}
        foreach(self::BASE_TABLES as $table)if(Schema::hasTable($table)!==$first){$this->error('Partial/unregistered fleet schema: '.$table);return self::FAILURE;}
        foreach(['fleet_depots','fleet_member_scopes'] as $table)if(Schema::hasTable($table)!==$second){$this->error('Partial/unregistered depot schema: '.$table);return self::FAILURE;}
        if(Schema::hasColumn('vehicles','depot_name')!==$first||Schema::hasColumn('vehicles','depot_id')!==$second||Schema::hasColumn('vehicles','home_depot_id')!==$second){$this->error('Partial vehicle fleet columns.');return self::FAILURE;}
        if($this->option('inspect')){$this->info('Read-only fleet preflight complete.');return self::SUCCESS;}
        $paths=array_map(fn($name)=>database_path('migrations/'.$name.'.php'),self::MIGRATIONS);
        foreach($paths as $path)if(!is_file($path)){$this->error('Fleet migration file missing.');return self::FAILURE;}
        $m->setOutput($this->output)->run($paths,['pretend'=>false,'step'=>true]);
        foreach([...self::BASE_TABLES,'fleet_depots','fleet_member_scopes','fleet_vehicle_couplings'] as $table)if(!Schema::hasTable($table)){$this->error('Fleet schema incomplete.');return self::FAILURE;}
        $this->info('Fleet schema ready. No database reset, seed, deletion or financial write performed.');return self::SUCCESS;
    }
}
