<?php

use App\Services\Accounting\BusinessWorkspace;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    private const TABLES = [
        'invoices', 'accounting_settings', 'accounting_partners', 'accounting_accounts',
        'accounting_periods', 'accounting_tax_rules', 'accounting_entries', 'accounting_bank_transactions',
        'accounting_payment_allocations', 'accounting_advances', 'accounting_cost_allocations',
        'accounting_cost_estimates', 'accounting_audit_events', 'accounting_issued_documents',
        'accounting_invoice_deliveries', 'catalog_products',
        'crm_documents', 'crm_contacts', 'crm_follow_ups', 'crm_logistics_cases',
        'ops_service_templates', 'ops_orders', 'ops_checklist_versions', 'ops_operational_state',
        'ops_inspection_cases', 'ops_transport_flows',
    ];

    public function up(): void
    {
        $this->changeSchema(function (): void {
            if (! Schema::hasTable('business_workspaces')) {
                Schema::create('business_workspaces', function (Blueprint $table): void {
                    $table->unsignedBigInteger('id')->primary();
                    $table->foreignId('company_id')->nullable()->unique()->constrained()->cascadeOnDelete()->cascadeOnUpdate();
                    $table->foreignId('user_id')->nullable()->unique()->constrained()->cascadeOnDelete();
                });
            }

            if (DB::table('companies')->where('id', '>=', BusinessWorkspace::PERSONAL_START)->exists()) {
                throw new LogicException('A company ID overlaps the reserved personal workspace range.');
            }

            DB::table('companies')->select('id')->chunkById(500, function ($companies): void {
                DB::table('business_workspaces')->insertOrIgnore($companies
                    ->map(fn ($company) => ['id' => $company->id, 'company_id' => $company->id])->all());
            });

            $this->pointTo('business_workspaces');
        });
    }

    public function down(): void
    {
        $this->changeSchema(function (): void {
            foreach (self::TABLES as $table) {
                if (DB::table($table)->where('company_id', '>=', BusinessWorkspace::PERSONAL_START)->exists()) {
                    throw new LogicException('Personal workspace records must be preserved.');
                }
            }

            $this->pointTo('companies');
            Schema::drop('business_workspaces');
        });
    }

    private function pointTo(string $parent): void
    {
        foreach (self::TABLES as $table) {
            $foreign = collect(Schema::getForeignKeys($table))
                ->firstWhere('columns', ['company_id']);
            if (($foreign['foreign_table'] ?? null) === $parent) {
                continue;
            }

            if ($foreign) {
                Schema::table($table, fn (Blueprint $t) => $t->dropForeign($foreign['name'] ?? ['company_id']));
            }

            Schema::table($table, function (Blueprint $t) use ($table, $parent): void {
                $key = $t->foreign('company_id')->references('id')->on($parent);
                $table === 'invoices' ? $key->nullOnDelete()->cascadeOnUpdate() : $key->restrictOnDelete();
            });
        }
    }

    private function changeSchema(Closure $change): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            // SQLite rebuilds the altered tables. Keep their child rows throughout the rebuild.
            Schema::withoutForeignKeyConstraints(fn () => DB::transaction($change));
        } else {
            $change();
        }
    }
};
