<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('vehicle_drafts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('user_id')->constrained('users');
            $t->unsignedBigInteger('company_id')->nullable();
            $t->string('active_scope')->nullable()->unique();
            $t->json('form');
            $t->unsignedInteger('revision')->default(1);
            $t->unsignedBigInteger('vehicle_id')->nullable();
            $t->timestamp('deleted_at')->nullable();
            $t->timestamps();
            $t->index(['user_id', 'company_id']);
        });
    }
    public function down(): void { Schema::dropIfExists('vehicle_drafts'); }
};
