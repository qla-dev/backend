<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up():void { Schema::create('form_drafts',function(Blueprint $t){
        $t->uuid('id')->primary();$t->foreignId('user_id')->constrained('users');$t->unsignedBigInteger('company_id')->nullable();
        $t->string('module',80);$t->string('form_key',120);$t->string('record_key',120)->default('new');$t->string('active_scope',64)->nullable()->unique();
        $t->string('state',20)->default('in_progress');$t->unsignedInteger('revision')->default(1);$t->longText('payload');
        $t->string('resource',80)->nullable();$t->unsignedBigInteger('resource_id')->nullable();$t->string('base_hash',64)->nullable();$t->string('base_version',255)->nullable();
        $t->string('request_hash',64)->nullable();$t->longText('result')->nullable();$t->unsignedSmallInteger('result_status')->nullable();$t->timestamps();
        $t->string('access_hash',64)->nullable();$t->unsignedBigInteger('access_company_id')->nullable();
        $t->index(['user_id','company_id','state']);
    }); }
    public function down():void {Schema::dropIfExists('form_drafts');}
};
