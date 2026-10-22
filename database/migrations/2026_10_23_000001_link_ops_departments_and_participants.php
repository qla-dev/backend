<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        if(!Schema::hasColumn('company_user','departments'))Schema::table('company_user',fn(Blueprint $t)=>$t->json('departments')->nullable());
        if(!Schema::hasTable('ops_order_participants'))Schema::create('ops_order_participants',function(Blueprint $t){
            $t->id();$t->foreignId('order_id')->constrained('ops_orders')->restrictOnDelete();$t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->string('role',20)->default('customer');$t->string('status',20)->default('invited');$t->unsignedInteger('revision')->default(1);
            $t->foreignId('invited_by')->constrained('users')->restrictOnDelete();$t->timestamp('expires_at')->nullable();$t->timestamp('accepted_at')->nullable();$t->timestamp('revoked_at')->nullable();$t->timestamps();$t->unique(['order_id','user_id']);
        });
    }
    public function down(): void {throw new RuntimeException('Operational participant and department history must be preserved.');}
};
