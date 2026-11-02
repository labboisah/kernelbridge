<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('license_platform_account_links', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('software_license_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->uuid('business_uuid');
            $table->string('status')->default('pending')->index();
            $table->foreignId('requested_by_api_client_id')->nullable()->constrained('api_clients')->nullOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['software_license_id', 'status'], 'platform_links_license_status_idx');
            $table->index(['product_id', 'business_uuid', 'status'], 'platform_links_business_status_idx');
        });

        Schema::table('license_activation_codes', function (Blueprint $table): void {
            $table->foreignId('license_platform_account_link_id')->nullable()->after('product_id')
                ->constrained('license_platform_account_links')->nullOnDelete();
            $table->uuid('bound_installation_uuid')->nullable()->after('redeemed_installation_uuid');
            $table->string('device_name')->nullable()->after('bound_installation_uuid');
        });

        Schema::table('license_installations', function (Blueprint $table): void {
            $table->foreignId('license_platform_account_link_id')->nullable()->after('product_id')
                ->constrained('license_platform_account_links')->nullOnDelete();
            $table->uuid('business_uuid')->nullable()->after('installation_uuid');
        });
    }

    public function down(): void
    {
        Schema::table('license_installations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('license_platform_account_link_id');
            $table->dropColumn('business_uuid');
        });

        Schema::table('license_activation_codes', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('license_platform_account_link_id');
            $table->dropColumn(['bound_installation_uuid', 'device_name']);
        });

        Schema::dropIfExists('license_platform_account_links');
    }
};
