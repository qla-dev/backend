<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('ops_checklist_versions', function (Blueprint $t) { $t->string('transport_type',20)->nullable(); $t->string('variant',20)->nullable(); });
        Schema::create('ops_transport_flows', function (Blueprint $t) {
            $t->id(); $t->foreignId('order_id')->unique()->constrained('ops_orders')->restrictOnDelete();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->string('transport_type', 20); $t->string('variant', 20)->nullable();
            $t->json('legs'); $t->json('transit'); $t->unsignedInteger('revision')->default(1); $t->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('ops_transport_flows'); Schema::table('ops_checklist_versions', fn (Blueprint $t) => $t->dropColumn(['transport_type','variant'])); }
};
