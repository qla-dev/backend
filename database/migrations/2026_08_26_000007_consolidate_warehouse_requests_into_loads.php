<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loads', function (Blueprint $table): void {
            $table->string('storage_type', 100)->nullable()->after('goods_type');
            $table->string('warehouse_city', 120)->nullable()->after('storage_type');
            $table->string('warehouse_country_code', 2)->nullable()->after('warehouse_city');
            $table->string('warehouse_address')->nullable()->after('warehouse_country_code');
            $table->decimal('warehouse_latitude', 10, 7)->nullable()->after('warehouse_address');
            $table->decimal('warehouse_longitude', 10, 7)->nullable()->after('warehouse_latitude');
            $table->date('storage_start_date')->nullable()->after('warehouse_longitude');
            $table->date('storage_end_date')->nullable()->after('storage_start_date');
            $table->boolean('is_storage_ongoing')->default(false)->after('storage_end_date');
            $table->json('handling_requirements')->nullable()->after('is_storage_ongoing');
            $table->boolean('requires_customs_bonded')->default(false)->after('handling_requirements');
            $table->boolean('requires_racking')->default(false)->after('requires_customs_bonded');
            $table->boolean('requires_security')->default(false)->after('requires_racking');
            $table->string('rate_unit', 50)->nullable()->after('requires_security');
            $table->unsignedBigInteger('customer_user_id')->nullable()->change();
            $table->string('cargo_type')->nullable()->change();
            $table->decimal('weight_kg', 12, 2)->nullable()->change();
        });

        $addedMovementLoadId = false;
        if (Schema::hasTable('warehouse_movements') && ! Schema::hasColumn('warehouse_movements', 'load_id')) {
            Schema::table('warehouse_movements', function (Blueprint $table): void {
                $table->unsignedBigInteger('load_id')->nullable()->after('warehouse_id');
            });
            $addedMovementLoadId = true;
        }

        if (Schema::hasTable('warehouse_requests')) {
            DB::table('warehouse_requests')->orderBy('id')->each(function (object $request): void {
                $loadId = DB::table('loads')->insertGetId([
                    'public_id' => $request->public_id ?: (string) Str::uuid(),
                    'customer_user_id' => $request->customer_user_id,
                    'title' => $request->title ?: 'Warehouse storage request',
                    'status' => $request->status,
                    'transport_type' => 'warehouse',
                    'cargo_type' => null,
                    'storage_type' => $request->storage_type,
                    'weight_kg' => $request->weight_kg,
                    'volume_m3' => $request->cbm,
                    'pallets' => $request->pallets,
                    'warehouse_city' => $request->city,
                    'warehouse_country_code' => $request->country_code,
                    'warehouse_address' => $request->address,
                    'warehouse_latitude' => $request->latitude,
                    'warehouse_longitude' => $request->longitude,
                    'storage_start_date' => $request->start_date,
                    'storage_end_date' => $request->end_date,
                    'is_storage_ongoing' => $request->is_ongoing,
                    'handling_requirements' => $request->handling_requirements,
                    'temperature_min' => $request->temperature_min,
                    'temperature_max' => $request->temperature_max,
                    'requires_customs_bonded' => $request->requires_customs_bonded,
                    'requires_racking' => $request->requires_racking,
                    'insurance_required' => $request->requires_insurance,
                    'requires_security' => $request->requires_security,
                    'budget' => $request->budget,
                    'currency' => $request->currency,
                    'rate_unit' => $request->rate_unit,
                    'is_negotiable' => $request->is_negotiable,
                    'notes' => $request->notes,
                    'internal_comments' => $request->internal_comments,
                    'external_comments' => $request->external_comments,
                    'contact' => $request->contact,
                    'published_at' => $request->published_at,
                    'created_at' => $request->created_at,
                    'updated_at' => $request->updated_at,
                ]);

                if (Schema::hasTable('warehouse_movements') && Schema::hasColumn('warehouse_movements', 'warehouse_request_id')) {
                    DB::table('warehouse_movements')->where('warehouse_request_id', $request->id)->update(['load_id' => $loadId]);
                }
            });

            if (Schema::hasTable('warehouse_movements') && Schema::hasColumn('warehouse_movements', 'warehouse_request_id')) {
                Schema::table('warehouse_movements', function (Blueprint $table): void {
                    $table->dropConstrainedForeignId('warehouse_request_id');
                });
            }

            Schema::drop('warehouse_requests');
        }

        if ($addedMovementLoadId) {
            Schema::table('warehouse_movements', function (Blueprint $table): void {
                $table->foreign('load_id')->references('id')->on('loads')->nullOnDelete()->cascadeOnUpdate();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('warehouse_requests')) {
            (include database_path('migrations/2026_08_26_000005_create_warehouse_requests_table.php'))->up();
        }

        $addedLegacyMovementId = false;
        if (Schema::hasTable('warehouse_movements') && ! Schema::hasColumn('warehouse_movements', 'warehouse_request_id')) {
            Schema::table('warehouse_movements', function (Blueprint $table): void {
                $table->unsignedBigInteger('warehouse_request_id')->nullable()->after('warehouse_id');
            });
            $addedLegacyMovementId = true;
        }

        DB::table('loads')->where('transport_type', 'warehouse')->orderBy('id')->each(function (object $load): void {
            $requestId = DB::table('warehouse_requests')->insertGetId([
                'public_id' => $load->public_id,
                'customer_user_id' => $load->customer_user_id,
                'title' => $load->title,
                'status' => $load->status,
                'storage_type' => $load->storage_type,
                'pallets' => $load->pallets,
                'cbm' => $load->volume_m3,
                'weight_kg' => $load->weight_kg,
                'city' => $load->warehouse_city,
                'country_code' => $load->warehouse_country_code,
                'address' => $load->warehouse_address,
                'latitude' => $load->warehouse_latitude,
                'longitude' => $load->warehouse_longitude,
                'start_date' => $load->storage_start_date,
                'end_date' => $load->storage_end_date,
                'is_ongoing' => $load->is_storage_ongoing,
                'handling_requirements' => $load->handling_requirements,
                'temperature_min' => $load->temperature_min,
                'temperature_max' => $load->temperature_max,
                'requires_customs_bonded' => $load->requires_customs_bonded,
                'requires_racking' => $load->requires_racking,
                'requires_insurance' => $load->insurance_required,
                'requires_security' => $load->requires_security,
                'budget' => $load->budget,
                'currency' => $load->currency,
                'rate_unit' => $load->rate_unit,
                'is_negotiable' => $load->is_negotiable,
                'notes' => $load->notes,
                'internal_comments' => $load->internal_comments,
                'external_comments' => $load->external_comments,
                'contact' => $load->contact,
                'published_at' => $load->published_at,
                'created_at' => $load->created_at,
                'updated_at' => $load->updated_at,
            ]);

            if (Schema::hasTable('warehouse_movements') && Schema::hasColumn('warehouse_movements', 'load_id')) {
                DB::table('warehouse_movements')->where('load_id', $load->id)->update(['warehouse_request_id' => $requestId]);
            }
        });

        DB::table('loads')->where('transport_type', 'warehouse')->delete();

        if (Schema::hasTable('warehouse_movements') && Schema::hasColumn('warehouse_movements', 'load_id')) {
            Schema::table('warehouse_movements', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('load_id');
            });
        }
        if ($addedLegacyMovementId) {
            Schema::table('warehouse_movements', function (Blueprint $table): void {
                $table->foreign('warehouse_request_id')->references('id')->on('warehouse_requests')->nullOnDelete()->cascadeOnUpdate();
            });
        }

        Schema::table('loads', function (Blueprint $table): void {
            $table->unsignedBigInteger('customer_user_id')->nullable(false)->change();
            $table->string('cargo_type')->nullable(false)->change();
            $table->decimal('weight_kg', 12, 2)->nullable(false)->change();
            $table->dropColumn([
                'storage_type', 'warehouse_city', 'warehouse_country_code', 'warehouse_address',
                'warehouse_latitude', 'warehouse_longitude', 'storage_start_date', 'storage_end_date',
                'is_storage_ongoing', 'handling_requirements', 'requires_customs_bonded',
                'requires_racking', 'requires_security', 'rate_unit',
            ]);
        });
    }
};
