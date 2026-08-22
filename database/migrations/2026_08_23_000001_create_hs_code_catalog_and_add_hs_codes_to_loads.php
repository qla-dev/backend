<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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

        Schema::table('loads', function (Blueprint $table): void {
            $table->json('hs_codes')->nullable()->after('goods_type');
        });

        $path = database_path('data/SmartFreight_HS2022_Master_5612.csv');
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException("HS catalog CSV is missing: {$path}");
        }

        try {
            $headers = fgetcsv($handle);
            if (! is_array($headers)) {
                throw new \RuntimeException('HS catalog CSV has no header row.');
            }
            $headers = array_map(
                fn (string $header): string => trim($header, "\xEF\xBB\xBF \t\n\r\0\x0B"),
                $headers,
            );

            $batch = [];
            while (($row = fgetcsv($handle)) !== false) {
                if (count($row) !== count($headers)) {
                    continue;
                }
                $item = array_combine($headers, $row);
                if (! is_array($item) || blank($item['hs_code'] ?? null)) {
                    continue;
                }

                $batch[] = [
                    'section_id' => (int) $item['section_id'],
                    'section_name' => $item['section_name'],
                    'chapter_code' => str_pad((string) $item['chapter_code'], 2, '0', STR_PAD_LEFT),
                    'chapter_name' => $item['chapter_name'],
                    'heading_code' => str_pad((string) $item['heading_code'], 4, '0', STR_PAD_LEFT),
                    'heading_name' => $item['heading_name'],
                    'hs_code' => str_pad((string) $item['hs_code'], 6, '0', STR_PAD_LEFT),
                    'description' => $item['hs_description'],
                    'parent_code' => filled($item['parent_code'] ?? null) ? (string) $item['parent_code'] : null,
                    'level' => (int) ($item['level'] ?: 6),
                    'version' => $item['version'] ?: 'HS 2022',
                ];

                if (count($batch) === 250) {
                    DB::table('hs_code_catalog')->insert($batch);
                    $batch = [];
                }
            }

            if ($batch !== []) {
                DB::table('hs_code_catalog')->insert($batch);
            }
        } finally {
            fclose($handle);
        }
    }

    public function down(): void
    {
        Schema::table('loads', function (Blueprint $table): void {
            $table->dropColumn('hs_codes');
        });
        Schema::dropIfExists('hs_code_catalog');
    }
};
