<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('load_notes', function (Blueprint $table) {
            $table->string('note_type', 40)->default('OTHER')->after('author_user_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('load_notes', function (Blueprint $table) {
            $table->dropIndex(['note_type']);
            $table->dropColumn('note_type');
        });
    }
};
