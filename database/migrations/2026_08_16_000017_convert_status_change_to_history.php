<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const FLOW = ['posted', 'opened', 'sent', 'in_delivery', 'received', 'finished'];

    public function up(): void
    {
        Schema::table('loads', function (Blueprint $table): void {
            $table->json('status_change_history')->nullable()->after('status_change');
        });

        DB::table('loads')->orderBy('id')->each(function (object $load): void {
            $createdAt = Carbon::parse($load->created_at)->toIso8601String();
            $changedAt = Carbon::parse($load->status_change ?? $load->updated_at ?? $load->created_at)->toIso8601String();
            $history = [];
            $currentIndex = array_search($load->status, self::FLOW, true);

            if ($currentIndex !== false) {
                foreach (array_slice(self::FLOW, 0, $currentIndex + 1) as $status) {
                    $history[$status] = $status === $load->status ? $changedAt : $createdAt;
                }
            } else {
                $history[$load->status] = $changedAt;
            }

            DB::table('loads')->where('id', $load->id)->update([
                'status_change_history' => json_encode($history, JSON_THROW_ON_ERROR),
            ]);
        });

        Schema::table('loads', function (Blueprint $table): void {
            $table->dropColumn('status_change');
        });
        Schema::table('loads', function (Blueprint $table): void {
            $table->renameColumn('status_change_history', 'status_change');
        });
    }

    public function down(): void
    {
        Schema::table('loads', function (Blueprint $table): void {
            $table->timestamp('status_change_timestamp')->nullable()->after('status_change');
        });

        DB::table('loads')->orderBy('id')->each(function (object $load): void {
            $history = json_decode((string) $load->status_change, true) ?: [];
            DB::table('loads')->where('id', $load->id)->update([
                'status_change_timestamp' => $history[$load->status] ?? (end($history) ?: null),
            ]);
        });

        Schema::table('loads', function (Blueprint $table): void {
            $table->dropColumn('status_change');
        });
        Schema::table('loads', function (Blueprint $table): void {
            $table->renameColumn('status_change_timestamp', 'status_change');
        });
    }
};
