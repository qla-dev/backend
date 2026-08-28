<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('hs_code_catalog');

        Schema::create('hs_code_catalog', function (Blueprint $table): void {
            $table->id();
            $table->string('ex', 20)->nullable();
            $table->string('tariff_code', 32)->nullable()->index();
            $table->text('name')->nullable();
            $table->text('section')->nullable();
            $table->text('chapter')->nullable();
            $table->string('previous_tariff_code', 32)->nullable()->index();
            $table->longText('full_name')->nullable();
            $table->longText('full_name_en')->nullable();
            $table->longText('full_name_de')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hs_code_catalog');

        Schema::create('hs_code_catalog', function (Blueprint $table): void {
            $table->id();
            $table->unsignedSmallInteger('section_id');
            $table->text('section_name');
            $table->string('chapter_code', 2)->index();
            $table->text('chapter_name');
            $table->string('heading_code', 4)->index();
            $table->text('heading_name');
            $table->string('hs_code', 6)->unique();
            $table->text('description');
            $table->string('parent_code', 6)->nullable();
            $table->unsignedTinyInteger('level')->default(6);
            $table->string('version', 20)->default('HS 2022');
        });
    }
};
