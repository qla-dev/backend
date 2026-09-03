<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Restore the core relationships that MyISAM silently omitted.
     *
     * This migration deliberately refuses to continue when existing rows would
     * violate a foreign key. It never deletes, updates, or "fixes" data.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $violations = [
            'users.role_id -> roles.id' => DB::table('users')
                ->leftJoin('roles', 'users.role_id', '=', 'roles.id')
                ->whereNull('roles.id')
                ->count(),
            'companies.owner_user_id -> users.id' => DB::table('companies')
                ->leftJoin('users', 'companies.owner_user_id', '=', 'users.id')
                ->whereNull('users.id')
                ->count(),
            'company_user.company_id -> companies.id' => DB::table('company_user')
                ->leftJoin('companies', 'company_user.company_id', '=', 'companies.id')
                ->whereNull('companies.id')
                ->count(),
            'company_user.user_id -> users.id' => DB::table('company_user')
                ->leftJoin('users', 'company_user.user_id', '=', 'users.id')
                ->whereNull('users.id')
                ->count(),
            'company_user.invited_by_user_id -> users.id' => DB::table('company_user')
                ->leftJoin('users', 'company_user.invited_by_user_id', '=', 'users.id')
                ->whereNotNull('company_user.invited_by_user_id')
                ->whereNull('users.id')
                ->count(),
        ];

        $invalid = array_filter($violations);

        if ($invalid !== []) {
            $details = collect($invalid)
                ->map(fn (int $count, string $relationship) => "{$relationship}: {$count}")
                ->implode(', ');

            throw new RuntimeException(
                "Foreign-key repair stopped. No schema or data was changed. Invalid existing references: {$details}"
            );
        }

        // Converting the engine preserves all existing rows and indexes.
        foreach (['roles', 'users', 'companies', 'company_user'] as $table) {
            DB::statement("ALTER TABLE `{$table}` ENGINE=InnoDB");
        }

        $this->addForeignKey('users', 'fk_users_role', 'role_id', 'roles');
        $this->addForeignKey('companies', 'fk_companies_owner_user', 'owner_user_id', 'users', 'RESTRICT');
        $this->addForeignKey('company_user', 'fk_company_user_company', 'company_id', 'companies', 'CASCADE');
        $this->addForeignKey('company_user', 'fk_company_user_user', 'user_id', 'users', 'CASCADE');
        $this->addForeignKey('company_user', 'fk_company_user_invited_by_user', 'invited_by_user_id', 'users', 'SET NULL');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach ([
            ['company_user', 'fk_company_user_invited_by_user'],
            ['company_user', 'fk_company_user_user'],
            ['company_user', 'fk_company_user_company'],
            ['companies', 'fk_companies_owner_user'],
            ['users', 'fk_users_role'],
        ] as [$table, $constraint]) {
            if ($this->foreignKeyExists($table, $constraint)) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropForeign($constraint));
            }
        }

        foreach (['company_user', 'companies', 'users', 'roles'] as $table) {
            DB::statement("ALTER TABLE `{$table}` ENGINE=MyISAM");
        }
    }

    private function addForeignKey(string $table, string $name, string $column, string $references, string $onDelete = 'RESTRICT'): void
    {
        if ($this->foreignKeyExists($table, $name)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($name, $column, $references, $onDelete) {
            $foreign = $blueprint->foreign($column, $name)
                ->references('id')
                ->on($references)
                ->onUpdate('cascade');

            match ($onDelete) {
                'CASCADE' => $foreign->onDelete('cascade'),
                'SET NULL' => $foreign->onDelete('set null'),
                default => $foreign->onDelete('restrict'),
            };
        });
    }

    private function foreignKeyExists(string $table, string $constraint): bool
    {
        return DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('CONSTRAINT_NAME', $constraint)
            ->where('CONSTRAINT_TYPE', 'FOREIGN KEY')
            ->exists();
    }
};
