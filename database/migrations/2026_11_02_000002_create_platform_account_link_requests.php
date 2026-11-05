<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('license_platform_account_links')) {
            Schema::create('license_platform_account_links', function (Blueprint $table): void {
                $table->id();
                $table->uuid('uuid');
                $table->unsignedBigInteger('software_license_id');
                $table->unsignedBigInteger('product_id');
                $table->uuid('business_uuid');
                $table->string('status')->default('pending');
                $table->unsignedBigInteger('requested_by_api_client_id')->nullable();
                $table->unsignedBigInteger('approved_by_user_id')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->timestamp('rejected_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->timestamps();
            });
        }

        $this->ensureForeignKey('license_platform_account_links', 'software_license_id', 'software_licenses', 'platform_links_license_fk', 'restrict');
        $this->ensureForeignKey('license_platform_account_links', 'product_id', 'products', 'platform_links_product_fk', 'restrict');
        $this->ensureForeignKey('license_platform_account_links', 'requested_by_api_client_id', 'api_clients', 'platform_links_requested_client_fk', 'set null');
        $this->ensureForeignKey('license_platform_account_links', 'approved_by_user_id', 'users', 'platform_links_approved_user_fk', 'set null');

        $this->ensureIndex('license_platform_account_links', ['uuid'], 'platform_links_uuid_unique', true);
        $this->ensureIndex('license_platform_account_links', ['status'], 'platform_links_status_idx');
        $this->ensureIndex('license_platform_account_links', ['software_license_id', 'status'], 'platform_links_license_status_idx');
        $this->ensureIndex('license_platform_account_links', ['product_id', 'business_uuid', 'status'], 'platform_links_business_status_idx');

        $this->ensureColumn('license_activation_codes', 'license_platform_account_link_id', fn (Blueprint $table) => $table->unsignedBigInteger('license_platform_account_link_id')->nullable()->after('product_id'));
        $this->ensureColumn('license_activation_codes', 'bound_installation_uuid', fn (Blueprint $table) => $table->uuid('bound_installation_uuid')->nullable()->after('redeemed_installation_uuid'));
        $this->ensureColumn('license_activation_codes', 'device_name', fn (Blueprint $table) => $table->string('device_name')->nullable()->after('bound_installation_uuid'));
        $this->ensureForeignKey('license_activation_codes', 'license_platform_account_link_id', 'license_platform_account_links', 'platform_links_activation_code_fk', 'set null');

        $this->ensureColumn('license_installations', 'license_platform_account_link_id', fn (Blueprint $table) => $table->unsignedBigInteger('license_platform_account_link_id')->nullable()->after('product_id'));
        $this->ensureColumn('license_installations', 'business_uuid', fn (Blueprint $table) => $table->uuid('business_uuid')->nullable()->after('installation_uuid'));
        $this->ensureForeignKey('license_installations', 'license_platform_account_link_id', 'license_platform_account_links', 'platform_links_installation_fk', 'set null');
    }

    public function down(): void
    {
        $this->dropColumnAndForeignKey('license_installations', 'license_platform_account_link_id');
        if (Schema::hasColumn('license_installations', 'business_uuid')) {
            Schema::table('license_installations', fn (Blueprint $table) => $table->dropColumn('business_uuid'));
        }

        $this->dropColumnAndForeignKey('license_activation_codes', 'license_platform_account_link_id');
        $columns = array_values(array_filter(
            ['bound_installation_uuid', 'device_name'],
            fn (string $column): bool => Schema::hasColumn('license_activation_codes', $column),
        ));
        if ($columns !== []) {
            Schema::table('license_activation_codes', fn (Blueprint $table) => $table->dropColumn($columns));
        }

        Schema::dropIfExists('license_platform_account_links');
    }

    private function ensureColumn(string $tableName, string $column, \Closure $definition): void
    {
        if (Schema::hasColumn($tableName, $column)) {
            return;
        }

        Schema::table($tableName, $definition);
    }

    private function ensureForeignKey(string $tableName, string $column, string $referencedTable, string $name, string $onDelete): void
    {
        foreach (Schema::getForeignKeys($tableName) as $foreignKey) {
            if ($foreignKey['columns'] === [$column] && $foreignKey['foreign_table'] === $referencedTable) {
                return;
            }
        }

        Schema::table($tableName, function (Blueprint $table) use ($column, $referencedTable, $name, $onDelete): void {
            $table->foreign($column, $name)->references('id')->on($referencedTable)->onDelete($onDelete);
        });
    }

    /** @param list<string> $columns */
    private function ensureIndex(string $tableName, array $columns, string $name, bool $unique = false): void
    {
        foreach (Schema::getIndexes($tableName) as $index) {
            if ($index['columns'] === $columns && $index['unique'] === $unique) {
                return;
            }
        }

        Schema::table($tableName, function (Blueprint $table) use ($columns, $name, $unique): void {
            $unique ? $table->unique($columns, $name) : $table->index($columns, $name);
        });
    }

    private function dropColumnAndForeignKey(string $tableName, string $column): void
    {
        if (! Schema::hasColumn($tableName, $column)) {
            return;
        }

        foreach (Schema::getForeignKeys($tableName) as $foreignKey) {
            if ($foreignKey['columns'] === [$column]) {
                Schema::table($tableName, fn (Blueprint $table) => $table->dropForeign($foreignKey['name']));
            }
        }

        Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn($column));
    }
};
