<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('loads')
            ->where('internal_comments', 'like', '[transport-workbook:%')
            ->orderBy('id')
            ->each(function (object $load): void {
                $source = json_decode((string) $load->notes, true);
                $sourceStatus = strtolower(trim((string) ($source['status'] ?? 'open')));
                $status = match ($sourceStatus) {
                    'finished', 'closed' => 'finished',
                    'pending' => 'pending',
                    'cancelled', 'canceled' => 'cancelled',
                    default => 'opened',
                };

                DB::table('loads')->where('id', $load->id)->update([
                    'status' => $status,
                    'completed_at' => $status === 'finished' ? ($load->completed_at ?? $load->updated_at) : null,
                ]);
            });

        DB::table('loads')->where('status', 'available')->update(['status' => 'posted']);
        DB::table('loads')->where('status', 'assigned')->update(['status' => 'sent']);
        DB::table('loads')->where('status', 'in_transit')->update(['status' => 'in_delivery']);
        DB::table('loads')->where('status', 'delivered')->update(['status' => 'received']);
        DB::table('loads')->where('status', 'completed')->update(['status' => 'finished']);

        Schema::table('shipments', function (Blueprint $table): void {
            $table->dropIndex(['status']);
            $table->dropColumn('status');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            $table->string('status')->default('pending')->index();
        });

        DB::table('loads')->where('status', 'posted')->update(['status' => 'available']);
        DB::table('loads')->where('status', 'sent')->update(['status' => 'assigned']);
        DB::table('loads')->where('status', 'in_delivery')->update(['status' => 'in_transit']);
        DB::table('loads')->where('status', 'received')->update(['status' => 'delivered']);
        DB::table('loads')->where('status', 'finished')->update(['status' => 'completed']);
    }
};
