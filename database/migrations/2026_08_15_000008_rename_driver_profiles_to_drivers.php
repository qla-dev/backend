<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('driver_profiles', 'drivers');
    }

    public function down(): void
    {
        Schema::rename('drivers', 'driver_profiles');
    }
};
