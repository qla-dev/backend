<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Read-only operational snapshot; never returns SQL text or connection secrets. */
final class DiagnoseFleetDatabase extends Command
{
    protected $signature = 'fleet:diagnose-database {--json : Print a JSON snapshot}';
    protected $description = 'Read MySQL connection pressure and process-state counts without changing configuration';

    public function handle(): int
    {
        $connection = DB::connection();
        if (!in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->error('This diagnostic requires MySQL or MariaDB. No changes made.');
            return self::FAILURE;
        }
        $data = ['captured_at' => now()->toIso8601String(), 'driver' => $connection->getDriverName()];
        try {
            $data['limits'] = collect($connection->select("SHOW GLOBAL VARIABLES WHERE Variable_name IN ('max_connections','max_user_connections','wait_timeout','interactive_timeout')"))->mapWithKeys(fn($r) => [$r->Variable_name => $r->Value])->all();
            $data['status'] = collect($connection->select("SHOW GLOBAL STATUS WHERE Variable_name IN ('Threads_connected','Threads_running','Max_used_connections','Connection_errors_max_connections')"))->mapWithKeys(fn($r) => [$r->Variable_name => $r->Value])->all();
            $processes = collect($connection->select('SHOW PROCESSLIST'));
            $data['visible_processes'] = $processes->count();
            $data['process_states'] = $processes->groupBy(fn($r) => $r->Command.' / '.($r->State ?: 'idle'))->map(fn($rows) => ['count' => $rows->count(), 'longest_seconds' => $rows->max('Time')])->all();
            $data['app_debug'] = (bool) config('app.debug');
            $data['environment'] = app()->environment();
            $data['note'] = 'Process visibility depends on DB privileges. Snapshot is read-only; SQL text, users and credentials are omitted.';
        } catch (\Throwable $e) {
            $this->error('Database diagnostics unavailable; check server logs or ask the hosting administrator. No changes made.');
            return self::FAILURE;
        }
        $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return self::SUCCESS;
    }
}
