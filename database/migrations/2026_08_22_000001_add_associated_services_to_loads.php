<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loads', function (Blueprint $table) {
            $table->boolean('insurance_required')->default(false)->after('customs_required');
            $table->boolean('certification_required')->default(false)->after('insurance_required');
            $table->boolean('inspection_services_required')->default(false)->after('certification_required');
        });
    }

    public function down(): void
    {
        Schema::table('loads', function (Blueprint $table) {
            $table->dropColumn([
                'insurance_required', 'certification_required', 'inspection_services_required',
            ]);
        });
    }
};
