<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lena_learning_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('program');
            $table->unsignedInteger('version');
            $table->json('state');
            $table->unsignedBigInteger('last_user_message_id')->nullable();
            $table->unsignedBigInteger('reply_message_id')->nullable();
            $table->timestamps();
            $table->unique(['conversation_id', 'user_id', 'program']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lena_learning_sessions');
    }
};
