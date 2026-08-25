<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Characteristics & certificates becomes a multi-select (same as special_requirements), so the
// single free-text column becomes a JSON array on both tables. doctrine/dbal isn't installed, so
// this drops and recreates the column instead of using ->change() - existing single values are
// preserved by wrapping them into a one-element array before the drop.
return new class extends Migration
{
    public function up(): void
    {
        $this->convert('loads');
        $this->convert('load_drafts');
    }

    private function convert(string $table): void
    {
        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->json('characteristics_json')->nullable()->after('characteristics');
        });

        DB::table($table)->whereNotNull('characteristics')->where('characteristics', '!=', '')->orderBy('id')->each(function (object $row) use ($table): void {
            DB::table($table)->where('id', $row->id)->update(['characteristics_json' => json_encode([$row->characteristics])]);
        });

        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->dropColumn('characteristics');
        });

        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->renameColumn('characteristics_json', 'characteristics');
        });
    }

    public function down(): void
    {
        $this->revert('loads');
        $this->revert('load_drafts');
    }

    private function revert(string $table): void
    {
        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->string('characteristics_str', 255)->nullable()->after('characteristics');
        });

        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->dropColumn('characteristics');
        });

        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->renameColumn('characteristics_str', 'characteristics');
        });
    }
};
