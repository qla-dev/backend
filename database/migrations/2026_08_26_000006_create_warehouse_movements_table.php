<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A single inbound/outbound ledger per warehouse. Every panel on the "Moj Warehouse" dashboard
// (occupancy, dock schedule, inventory summary, recent arrivals, top customers, revenue) is derived
// from this one table instead of separate dock/inventory tables - net pallets per warehouse
// (inbound minus outbound, completed rows only) is the occupancy figure, grouping by storage_type
// gives the inventory summary, and grouping by customer_name gives top customers by storage.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('load_id')->nullable()->constrained('loads')->nullOnDelete()->cascadeOnUpdate();
            $table->string('direction'); // inbound | outbound
            $table->string('status')->default('scheduled'); // scheduled | completed
            $table->timestamp('scheduled_at');
            $table->timestamp('completed_at')->nullable();
            $table->string('dock_number', 20)->nullable();
            $table->string('customer_name')->nullable();
            $table->string('storage_type')->nullable();
            $table->unsignedInteger('pallets')->default(0);
            $table->decimal('cbm', 10, 2)->nullable();
            $table->decimal('weight_kg', 12, 2)->nullable();
            $table->decimal('rate', 14, 2)->nullable();
            $table->char('currency', 3)->default('EUR');
            $table->string('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_movements');
    }
};
