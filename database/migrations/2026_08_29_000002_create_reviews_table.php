<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('reviewer_user_id')->constrained('users')->cascadeOnDelete()->cascadeOnUpdate();
            $table->string('reviewable_type', 40);
            $table->unsignedBigInteger('reviewable_id');
            $table->string('mode', 24);
            $table->unsignedTinyInteger('rating');
            $table->json('criteria');
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->index(['reviewable_type', 'reviewable_id']);
            $table->unique(
                ['reviewer_user_id', 'reviewable_type', 'reviewable_id'],
                'reviews_reviewer_target_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
