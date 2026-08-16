<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loads', function (Blueprint $table): void {
            $table->string('booking_reference', 160)->nullable()->index()->after('title');
            $table->string('insurance')->nullable()->after('booking_reference');
            $table->string('department', 120)->nullable()->after('insurance');
            $table->string('freight_mode', 120)->nullable()->after('department');
            $table->string('subdepartment', 120)->nullable()->after('freight_mode');
            $table->string('quantity_measure')->nullable()->after('pallets');
            $table->string('teu', 80)->nullable()->after('quantity_measure');
            $table->string('container_types')->nullable()->after('teu');
            $table->string('container_number')->nullable()->after('container_types');
            $table->timestamp('etd_at')->nullable()->after('container_number');
            $table->timestamp('atd_at')->nullable()->after('etd_at');
            $table->string('shipper_name')->nullable()->after('atd_at');
            $table->string('mediator')->nullable()->after('shipper_name');
            $table->string('incoterms', 80)->nullable()->after('mediator');
            $table->text('price_insurance')->nullable()->after('incoterms');
            $table->text('profit_loss')->nullable()->after('price_insurance');
        });

        DB::table('loads')
            ->where('internal_comments', 'like', '[transport-workbook:%')
            ->orderBy('id')
            ->each(function (object $load): void {
                $source = json_decode((string) $load->notes, true);
                if (! is_array($source)) return;

                DB::table('loads')->where('id', $load->id)->update([
                    'booking_reference' => $this->text($source['booking'] ?? null),
                    'insurance' => $this->text($source['insurance'] ?? null),
                    'department' => $this->text($source['department'] ?? null),
                    'freight_mode' => $this->text($source['freight_mode'] ?? null),
                    'subdepartment' => $this->text($source['subdepartment'] ?? null),
                    'quantity_measure' => $this->text($source['quantity'] ?? null),
                    'teu' => $this->text($source['teu'] ?? null),
                    'container_types' => $this->text($source['container_types'] ?? null),
                    'container_number' => $this->text($source['container'] ?? null),
                    'etd_at' => $this->date($source['etd'] ?? null),
                    'atd_at' => $this->date($source['atd'] ?? null),
                    'shipper_name' => $this->text($source['shipper'] ?? null),
                    'mediator' => $this->text($source['mediator'] ?? null),
                    'incoterms' => $this->text($source['incoterms'] ?? null),
                    'price_insurance' => $this->text($source['price'] ?? null),
                    'profit_loss' => $this->text($source['profit_loss'] ?? null),
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('loads', function (Blueprint $table): void {
            $table->dropIndex(['booking_reference']);
            $table->dropColumn([
                'booking_reference', 'insurance', 'department', 'freight_mode', 'subdepartment',
                'quantity_measure', 'teu', 'container_types', 'container_number', 'etd_at', 'atd_at',
                'shipper_name', 'mediator', 'incoterms', 'price_insurance', 'profit_loss',
            ]);
        });
    }

    private function text(mixed $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private function date(mixed $value): ?string
    {
        $value = strtolower(trim((string) $value));
        if ($value === '' || in_array($value, ['-', '.', 'pending'], true)) return null;
        if (preg_match('/^(\d{1,2})\.\s*(\d{1,2})\.\s*(\d{4})\.?$/', $value, $match)) {
            return Carbon::create((int) $match[3], (int) $match[2], (int) $match[1])->startOfDay()->toDateTimeString();
        }
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $value, $match)) {
            return Carbon::create((int) $match[3], (int) $match[1], (int) $match[2])->startOfDay()->toDateTimeString();
        }
        try {
            return Carbon::parse($value)->startOfDay()->toDateTimeString();
        } catch (Throwable) {
            return null;
        }
    }
};
