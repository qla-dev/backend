<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $invalid = [];
        foreach ($this->keys() as [$table, $column, $parent, , $nullable]) {
            $query = DB::table("{$table} as c")->leftJoin("{$parent} as p", "c.{$column}", '=', 'p.id')->whereNull('p.id');
            if ($nullable) {
                $query->whereNotNull("c.{$column}");
            }
            if ($count = $query->count()) {
                $invalid["{$table}.{$column} -> {$parent}.id"] = $count;
            }
        }
        if ($invalid) {
            throw new RuntimeException('Foreign-key repair stopped; no schema changed. Invalid references: '.collect($invalid)->map(fn ($n, $k) => "$k: $n")->implode(', '));
        }

        foreach (collect($this->keys())->flatMap(fn ($k) => [$k[0], $k[2]])->unique()->sort() as $table) {
            DB::statement("ALTER TABLE `{$table}` ENGINE=InnoDB");
        }
        foreach ($this->keys() as [$table,$column,$parent,$delete]) {
            $this->add($table, $column, $parent, $delete);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach (array_reverse($this->keys()) as [$table,$column]) {
            $name = $this->name($table, $column);
            if (DB::table('information_schema.TABLE_CONSTRAINTS')->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())->where('TABLE_NAME', $table)->where('CONSTRAINT_NAME', $name)->exists()) {
                Schema::table($table, fn (Blueprint $t) => $t->dropForeign($name));
            }
        }
    }

    private function keys(): array
    {
        return array_map(function ($line) {
            [$t,$c,$p,$d,$n] = explode('|', $line);

            return [$t, $c, $p, $d, $n === '1'];
        }, [
            'users|role_id|roles|restrict|0', 'companies|owner_user_id|users|restrict|0', 'company_user|company_id|companies|cascade|0', 'company_user|user_id|users|cascade|0', 'company_user|invited_by_user_id|users|null|1', 'sessions|user_id|users|cascade|1',
            'drivers|user_id|users|cascade|0', 'drivers|primary_company_id|companies|null|1', 'vehicles|company_id|companies|null|1', 'vehicles|owner_user_id|users|null|1', 'vehicles|assigned_driver_user_id|users|null|1', 'fleet_access|vehicle_id|vehicles|cascade|0', 'fleet_access|user_id|users|cascade|0', 'fleet_access|granted_by_user_id|users|null|1', 'vehicle_locations|vehicle_id|vehicles|cascade|0',
            'loads|customer_user_id|users|restrict|0', 'loads|consignee_customer_id|customers|null|1', 'loads|company_id|companies|null|1', 'loads|assigned_driver_user_id|users|null|1', 'loads|vehicle_id|vehicles|null|1', 'load_stops|load_id|loads|cascade|0', 'offers|load_id|loads|cascade|0', 'offers|company_id|companies|null|1', 'offers|driver_user_id|users|null|1', 'offers|created_by_user_id|users|restrict|0', 'routes|load_id|loads|cascade|0', 'route_stops|route_id|routes|cascade|0', 'route_stops|load_stop_id|load_stops|null|1', 'tracking_events|shipment_id|shipments|cascade|0', 'tracking_events|route_id|routes|null|1', 'tracking_events|vehicle_id|vehicles|null|1', 'tracking_events|created_by_user_id|users|null|1', 'load_notes|load_id|loads|cascade|0', 'load_notes|author_user_id|users|restrict|0', 'documents|load_id|loads|cascade|1', 'documents|vehicle_id|vehicles|cascade|1', 'documents|user_id|users|cascade|1', 'documents|uploaded_by_user_id|users|restrict|0',
            'conversations|company_id|companies|cascade|1', 'conversations|load_id|loads|cascade|1', 'conversations|created_by_user_id|users|restrict|0', 'conversation_user|conversation_id|conversations|cascade|0', 'conversation_user|user_id|users|cascade|0', 'messages|conversation_id|conversations|cascade|0', 'messages|sender_user_id|users|restrict|0', 'invoices|customer_user_id|users|restrict|0', 'invoices|company_id|companies|null|1', 'invoices|load_id|loads|null|1', 'invoices|issued_by_user_id|users|restrict|0', 'invoice_items|invoice_id|invoices|cascade|0', 'invoice_items|load_id|loads|null|1', 'email_templates|created_by_user_id|users|restrict|0', 'email_campaigns|email_template_id|email_templates|restrict|0', 'email_campaigns|created_by_user_id|users|restrict|0', 'email_campaign_recipients|email_campaign_id|email_campaigns|cascade|0', 'email_campaign_recipients|company_id|companies|cascade|1', 'email_campaign_recipients|user_id|users|cascade|1', 'company_invitations|company_id|companies|cascade|0', 'company_invitations|role_id|roles|restrict|0', 'company_invitations|invited_by_user_id|users|restrict|0', 'company_invitations|accepted_by_user_id|users|null|1', 'customers|user_id|users|cascade|1',
        ]);
    }

    private function add(string $table, string $column, string $parent, string $delete): void
    {
        if (DB::table('information_schema.KEY_COLUMN_USAGE')->where('TABLE_SCHEMA', DB::getDatabaseName())->where('TABLE_NAME', $table)->where('COLUMN_NAME', $column)->where('REFERENCED_TABLE_NAME', $parent)->exists()) {
            return;
        }
        $name = $this->name($table, $column);
        Schema::table($table, function (Blueprint $t) use ($name, $column, $parent, $delete) {
            $fk = $t->foreign($column, $name)->references('id')->on($parent)->onUpdate('cascade');
            $delete === 'cascade' ? $fk->onDelete('cascade') : ($delete === 'null' ? $fk->onDelete('set null') : $fk->onDelete('restrict'));
        });
    }

    private function name(string $table, string $column): string
    {
        return "fk_{$table}_{$column}";
    }
};
