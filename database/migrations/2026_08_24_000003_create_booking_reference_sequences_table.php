<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// One row per (type_code, year) counter backing BookingReferenceGenerator's FB-{type}-{YY}{NNN}
// format. A dedicated, row-locked counter table (rather than MAX(booking_reference)+1 over loads/
// load_drafts) is what makes concurrent draft/load creation collision-safe.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_reference_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('type_code', 1);
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('next_value')->default(0);
            $table->timestamps();
            $table->unique(['type_code', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_reference_sequences');
    }
};
