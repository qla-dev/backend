<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('fleet_depots', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->string('code', 40); $t->string('name', 120); $t->string('city', 120)->nullable();
            $t->string('address', 255)->nullable(); $t->boolean('active')->default(true); $t->timestamps();
            $t->unique(['company_id', 'code']);
        });
        Schema::create('fleet_member_scopes', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('user_id'); $t->string('fleet_role', 30); $t->boolean('all_depots')->default(false);
            $t->json('depot_ids'); $t->json('vehicle_ids')->nullable(); $t->timestamps();
            $t->unique(['company_id','user_id']);
        });
        foreach (['vehicles','fleet_handovers','fleet_tours','fleet_damages'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->foreignId('depot_id')->nullable()->constrained('fleet_depots')->restrictOnDelete();
            });
        }
        Schema::table('vehicles', function (Blueprint $t) {
            $t->foreignId('home_depot_id')->nullable()->constrained('fleet_depots')->restrictOnDelete();
        });
        Schema::table('fleet_handovers', function (Blueprint $t) {
            $t->foreignId('from_depot_id')->nullable()->constrained('fleet_depots')->restrictOnDelete();
        });
        // Exact existing names only, company by company. Never infer an old depot from today's vehicle.
        $names = DB::table('vehicles')->whereNotNull('company_id')->whereNotNull('depot_name')->select('company_id','depot_name')
            ->union(DB::table('fleet_handovers')->whereNotNull('depot_name')->select('company_id','depot_name'))
            ->union(DB::table('fleet_tours')->select('company_id','depot_name'))->get();
        foreach ($names as $row) {
            if (trim($row->depot_name) === '') continue;
            $id = DB::table('fleet_depots')->insertGetId(['company_id'=>$row->company_id,
                'code'=>'LEGACY-'.substr(hash('sha256',$row->depot_name),0,16), 'name'=>$row->depot_name,
                'active'=>true,'created_at'=>now(),'updated_at'=>now()]);
            foreach (['vehicles','fleet_handovers','fleet_tours'] as $table) {
                DB::table($table)->where('company_id',$row->company_id)->where('depot_name',$row->depot_name)->update(['depot_id'=>$id]);
            }
        }
        // Damage visibility follows its original handover, not later vehicle transfers.
        DB::table('fleet_damages')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) if ($row->handover_id) DB::table('fleet_damages')->where('id',$row->id)
                ->update(['depot_id'=>DB::table('fleet_handovers')->where('id',$row->handover_id)->value('depot_id')]);
        });
        Schema::table('fleet_tours', function (Blueprint $t) {
            $t->dropUnique('fleet_tour_identity');
            $t->unique(['company_id','service_date','depot_id','partner','tour_code'],'fleet_tour_depot_identity');
        });
        // Preserve existing company-level operational grants explicitly. New members get no implicit depot grant.
        DB::table('company_user')->where('status','active')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                $role=DB::table('roles')->where('id',$row->company_role_id)->value('name');
                if (!in_array($role,['manager','dispatcher'],true)) continue;
                DB::table('fleet_member_scopes')->insert(['company_id'=>$row->company_id,'user_id'=>$row->user_id,
                    'fleet_role'=>$role==='manager'?'fleet_manager':'dispatcher','all_depots'=>true,'depot_ids'=>'[]',
                    'created_at'=>now(),'updated_at'=>now()]);
            }
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Depot and access history must be retained. Use an explicit forward migration.');
    }
};
