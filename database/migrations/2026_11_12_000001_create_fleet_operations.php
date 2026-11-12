<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $t) {
            $t->string('depot_name', 120)->nullable();
            $t->boolean('dispatch_blocked')->default(false);
        });
        Schema::create('fleet_handovers', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('vehicle_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('load_id')->nullable();
            $t->unsignedBigInteger('inspection_id')->nullable()->unique();
            $t->unsignedBigInteger('from_driver_id')->nullable(); $t->unsignedBigInteger('to_driver_id')->nullable();
            $t->unsignedBigInteger('recorded_by')->nullable(); $t->unsignedBigInteger('reviewed_by')->nullable();
            $t->string('destination', 20); $t->string('depot_name', 120)->nullable();
            $t->string('plate', 80); $t->unsignedBigInteger('mileage_km'); $t->unsignedTinyInteger('fuel_level_percent');
            $t->boolean('has_damage')->default(false); $t->text('notes')->nullable();
            $t->unsignedBigInteger('damage_id')->nullable();
            $t->string('review_status', 20)->default('pending'); $t->timestamp('reviewed_at')->nullable();
            $t->timestamp('effective_at'); $t->timestamps();
            $t->index(['company_id', 'effective_at']); $t->index(['vehicle_id', 'effective_at']);
        });
        Schema::create('fleet_assignments', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('vehicle_id')->constrained()->restrictOnDelete(); $t->unsignedBigInteger('driver_id');
            $t->foreignId('handover_id')->nullable()->constrained('fleet_handovers')->restrictOnDelete();
            $t->timestamp('started_at'); $t->timestamp('ended_at')->nullable(); $t->timestamps();
            $t->index(['vehicle_id', 'started_at', 'ended_at']); $t->index(['driver_id', 'ended_at']);
        });
        Schema::create('fleet_damages', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('vehicle_id')->constrained()->restrictOnDelete();
            $t->foreignId('handover_id')->nullable()->constrained('fleet_handovers')->restrictOnDelete();
            $t->unsignedBigInteger('recorded_by'); $t->string('kind', 20)->default('internal');
            $t->string('status', 20)->default('reported'); $t->text('description');
            $t->json('details')->nullable(); $t->timestamp('closed_at')->nullable(); $t->timestamps();
            $t->index(['company_id', 'status']);
        });
        Schema::create('fleet_files', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('handover_id')->nullable()->constrained('fleet_handovers')->restrictOnDelete();
            $t->foreignId('damage_id')->nullable()->constrained('fleet_damages')->restrictOnDelete();
            $t->string('name'); $t->string('path'); $t->string('mime_type'); $t->unsignedBigInteger('size_bytes'); $t->timestamps();
        });
        Schema::create('fleet_tours', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->date('service_date'); $t->string('depot_name', 120); $t->string('partner', 80); $t->string('tour_code', 120);
            $t->unsignedBigInteger('driver_id')->nullable(); $t->unsignedBigInteger('vehicle_id')->nullable();
            $t->unsignedBigInteger('assignment_id')->nullable(); $t->string('plate', 80)->nullable();
            $t->string('status', 20)->default('open'); $t->unsignedBigInteger('locked_by')->nullable();
            $t->timestamp('locked_at')->nullable(); $t->timestamp('completed_at')->nullable();
            $t->string('partner_url', 2000)->nullable(); $t->string('source_driver_name')->nullable(); $t->unsignedBigInteger('created_by'); $t->timestamps();
            $t->unique(['company_id', 'service_date', 'depot_name', 'partner', 'tour_code'], 'fleet_tour_identity');
            $t->index(['company_id', 'service_date']);
        });
        Schema::create('fleet_events', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('actor_id'); $t->string('entity_type', 30); $t->unsignedBigInteger('entity_id');
            $t->string('action', 40); $t->json('details')->nullable(); $t->timestamps();
            $t->index(['company_id', 'entity_type', 'entity_id']);
        });
        Schema::create('fleet_notifications', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('damage_id')->constrained('fleet_damages')->restrictOnDelete();
            $t->unsignedBigInteger('read_by')->nullable(); $t->timestamp('read_at')->nullable(); $t->timestamps();
        });
        Schema::create('fleet_import_profiles', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained()->restrictOnDelete(); $t->string('partner',80);
            $t->string('header_hash',64); $t->json('mapping'); $t->timestamps();
            $t->unique(['company_id','partner','header_hash'],'fleet_import_profile_key');
        });
        // Preserve existing condition reports; review is deliberately still pending.
        if (Schema::hasTable('vehicle_return_inspections')) {
            DB::table('vehicle_return_inspections')->orderBy('id')->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    $v = DB::table('vehicles')->find($row->vehicle_id);
                    if (!$v?->company_id) continue;
                    DB::table('fleet_handovers')->insert([
                        'company_id'=>$v->company_id, 'vehicle_id'=>$v->id, 'load_id'=>$row->load_id,
                        'from_driver_id'=>$row->load_id?DB::table('loads')->where('id',$row->load_id)->value('assigned_driver_user_id'):null,
                        'inspection_id'=>$row->id, 'recorded_by'=>$row->recorded_by_user_id,
                        'destination'=>'depot', 'plate'=>$v->registration_number, 'mileage_km'=>$row->mileage_km,
                        'fuel_level_percent'=>$row->fuel_level_percent, 'has_damage'=>$row->has_damage,
                        'notes'=>$row->damage_notes, 'effective_at'=>$row->inspected_at,
                        'created_at'=>$row->created_at, 'updated_at'=>$row->updated_at,
                    ]);
                }
            });
        }
        // Current assignments remain valid across days, without manufacturing a handover.
        DB::table('vehicles')->whereNotNull('company_id')->whereNotNull('assigned_driver_user_id')->whereNotIn('status',['sold','returned','stolen'])->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $v) DB::table('fleet_assignments')->insert([
                'company_id'=>$v->company_id, 'vehicle_id'=>$v->id, 'driver_id'=>$v->assigned_driver_user_id,
                'started_at'=>now(), 'created_at'=>now(), 'updated_at'=>now(),
            ]);
        });
    }

    public function down(): void
    {
        // Operational history must never be deleted by a routine rollback.
        throw new RuntimeException('Fleet history requires an explicit archival migration.');
    }
};
