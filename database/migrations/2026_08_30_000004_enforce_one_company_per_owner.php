<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $duplicateOwnerIds = DB::table('companies')
                ->select('owner_user_id')
                ->groupBy('owner_user_id')
                ->havingRaw('COUNT(*) > 1')
                ->pluck('owner_user_id');

            foreach ($duplicateOwnerIds as $ownerUserId) {
                $companies = DB::table('companies')
                    ->where('owner_user_id', $ownerUserId)
                    ->orderByDesc('updated_at')
                    ->orderByDesc('id')
                    ->get();

                $survivorId = (int) $companies->first()->id;

                foreach ($companies->skip(1) as $duplicate) {
                    $duplicateId = (int) $duplicate->id;

                    DB::table('company_user')->where('company_id', $duplicateId)->orderBy('id')->get()->each(
                        function ($membership) use ($survivorId): void {
                            $existing = DB::table('company_user')
                                ->where('company_id', $survivorId)
                                ->where('user_id', $membership->user_id)
                                ->first();

                            if ($existing) {
                                DB::table('company_user')->where('id', $membership->id)->delete();

                                return;
                            }

                            DB::table('company_user')->where('id', $membership->id)->update(['company_id' => $survivorId]);
                        }
                    );

                    DB::table('company_invitations')->where('company_id', $duplicateId)->orderBy('id')->get()->each(
                        function ($invitation) use ($survivorId): void {
                            $conflicts = DB::table('company_invitations')
                                ->where('company_id', $survivorId)
                                ->where('email', $invitation->email)
                                ->where('status', $invitation->status)
                                ->exists();

                            if ($conflicts) {
                                DB::table('company_invitations')->where('id', $invitation->id)->delete();

                                return;
                            }

                            DB::table('company_invitations')->where('id', $invitation->id)->update(['company_id' => $survivorId]);
                        }
                    );

                    foreach ([
                        'conversations',
                        'email_campaign_recipients',
                        'invoices',
                        'loads',
                        'load_drafts',
                        'offers',
                        'vehicles',
                    ] as $table) {
                        if (Schema::hasTable($table) && Schema::hasColumn($table, 'company_id')) {
                            DB::table($table)->where('company_id', $duplicateId)->update(['company_id' => $survivorId]);
                        }
                    }

                    if (Schema::hasTable('drivers') && Schema::hasColumn('drivers', 'primary_company_id')) {
                        DB::table('drivers')->where('primary_company_id', $duplicateId)->update(['primary_company_id' => $survivorId]);
                    }

                    DB::table('companies')->where('id', $duplicateId)->delete();
                }
            }
        });

        Schema::table('companies', function (Blueprint $table): void {
            $table->unique('owner_user_id', 'companies_owner_user_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropUnique('companies_owner_user_id_unique');
        });
    }
};
