<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Everything the multi-step "Add Warehouse" wizard captures. Fields a query or a listing filter
// actually needs stay real columns; the long-tail configuration blocks (zones, equipment counts,
// capability toggles, permits) are JSON so the form can grow without another migration each time.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $table->string('code', 60)->nullable()->after('name');
            $table->string('warehouse_type', 60)->nullable()->after('code');
            $table->text('description')->nullable()->after('warehouse_type');

            $table->string('address_line_2')->nullable()->after('address');
            $table->string('state_province', 120)->nullable()->after('city');
            $table->string('postal_code', 30)->nullable()->after('state_province');

            $table->string('contact_name')->nullable()->after('registration_number');
            $table->string('contact_email')->nullable()->after('contact_name');
            $table->string('contact_phone', 50)->nullable()->after('contact_email');
            $table->string('contact_alternate_phone', 50)->nullable()->after('contact_phone');
            $table->string('department', 80)->nullable()->after('contact_alternate_phone');
            $table->string('preferred_contact_method', 20)->nullable()->after('department');
            $table->string('manager_name')->nullable()->after('preferred_contact_method');
            $table->string('manager_email')->nullable()->after('manager_name');
            $table->string('manager_phone', 50)->nullable()->after('manager_email');

            $table->unsignedInteger('total_capacity_cbm')->default(0)->after('total_capacity_pallets');
            $table->unsignedInteger('storage_area_sqm')->default(0)->after('total_capacity_cbm');
            $table->unsignedSmallInteger('dock_doors')->default(0)->after('storage_area_sqm');

            $table->text('operational_notes')->nullable()->after('certifications');
            $table->text('documents_notes')->nullable()->after('operational_notes');

            $table->json('utilization_thresholds')->nullable()->after('documents_notes');
            $table->json('storage_config')->nullable()->after('utilization_thresholds');
            $table->json('temperature_zones')->nullable()->after('storage_config');
            $table->json('inventory_settings')->nullable()->after('temperature_zones');
            $table->json('equipment')->nullable()->after('inventory_settings');
            $table->json('handling_capabilities')->nullable()->after('equipment');
            $table->json('operations')->nullable()->after('handling_capabilities');
            $table->json('capabilities')->nullable()->after('operations');
            $table->json('technology')->nullable()->after('capabilities');
            $table->json('compliance')->nullable()->after('technology');
            $table->json('standards')->nullable()->after('compliance');
            $table->json('documents')->nullable()->after('standards');
        });
    }

    public function down(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $table->dropColumn([
                'code', 'warehouse_type', 'description',
                'address_line_2', 'state_province', 'postal_code',
                'contact_name', 'contact_email', 'contact_phone', 'contact_alternate_phone',
                'department', 'preferred_contact_method',
                'manager_name', 'manager_email', 'manager_phone',
                'total_capacity_cbm', 'storage_area_sqm', 'dock_doors',
                'operational_notes', 'documents_notes',
                'utilization_thresholds', 'storage_config', 'temperature_zones', 'inventory_settings',
                'equipment', 'handling_capabilities', 'operations', 'capabilities', 'technology',
                'compliance', 'standards', 'documents',
            ]);
        });
    }
};
