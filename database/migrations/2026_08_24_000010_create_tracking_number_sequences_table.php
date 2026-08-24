<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracking_number_sequences', function (Blueprint $table): void {
            $table->id();
            $table->char('type_code', 1);
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('next_value')->default(0);
            $table->timestamps();
            $table->unique(['type_code', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracking_number_sequences');
    }
};
