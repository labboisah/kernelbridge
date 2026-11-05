<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('license_activation_codes', function (Blueprint $table): void {
            $table->string('entitlement_source', 32)->nullable()->after('license_platform_account_link_id');
        });

        Schema::table('license_installations', function (Blueprint $table): void {
            $table->string('entitlement_source', 32)->nullable()->after('license_platform_account_link_id');
        });
    }

    public function down(): void
    {
        Schema::table('license_installations', function (Blueprint $table): void {
            $table->dropColumn('entitlement_source');
        });

        Schema::table('license_activation_codes', function (Blueprint $table): void {
            $table->dropColumn('entitlement_source');
        });
    }
};
