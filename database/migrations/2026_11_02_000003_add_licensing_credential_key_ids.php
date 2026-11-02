<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('license_activation_codes', function (Blueprint $table): void {
            $table->string('code_lookup_key_id')->nullable()->after('code_lookup');
        });

        Schema::table('license_installations', function (Blueprint $table): void {
            $table->string('credential_key_id')->nullable()->after('credential_hash');
        });

        Schema::table('license_installation_grants', function (Blueprint $table): void {
            $table->string('credential_key_id')->nullable()->after('credential_hash');
        });
    }

    public function down(): void
    {
        Schema::table('license_installation_grants', function (Blueprint $table): void {
            $table->dropColumn('credential_key_id');
        });

        Schema::table('license_installations', function (Blueprint $table): void {
            $table->dropColumn('credential_key_id');
        });

        Schema::table('license_activation_codes', function (Blueprint $table): void {
            $table->dropColumn('code_lookup_key_id');
        });
    }
};
