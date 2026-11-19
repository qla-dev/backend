<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('fleet_vehicle_couplings',function(Blueprint $t){
            $t->id();$t->unsignedBigInteger('company_id');$t->unsignedBigInteger('towing_vehicle_id');$t->unsignedBigInteger('trailer_vehicle_id');
            $t->unsignedTinyInteger('position')->default(1);$t->string('active_slot',80)->nullable()->unique();
            $t->unsignedBigInteger('active_trailer_id')->nullable()->unique();
            $t->unsignedBigInteger('attached_by');$t->unsignedBigInteger('detached_by')->nullable();
            $t->timestamp('started_at');$t->timestamp('ended_at')->nullable();$t->text('attach_reason');$t->text('detach_reason')->nullable();$t->timestamps();
            $t->index(['company_id','towing_vehicle_id','ended_at'],'fleet_coupling_towing');
            $t->index(['company_id','trailer_vehicle_id','ended_at'],'fleet_coupling_trailer');
            $t->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $t->foreign('towing_vehicle_id')->references('id')->on('vehicles')->restrictOnDelete();
            $t->foreign('trailer_vehicle_id')->references('id')->on('vehicles')->restrictOnDelete();
        });
    }
    public function down(): void { Schema::dropIfExists('fleet_vehicle_couplings'); }
};
