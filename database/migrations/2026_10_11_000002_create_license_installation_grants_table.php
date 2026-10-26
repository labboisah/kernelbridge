<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('license_installation_grants', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('software_license_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('issued_by_api_client_id')->constrained('api_clients')->restrictOnDelete();
            $table->uuid('business_uuid');
            $table->uuid('branch_uuid');
            $table->uuid('device_uuid');
            $table->uuid('installation_uuid');
            $table->char('credential_hash', 64);
            $table->text('credential_encrypted')->nullable();
            $table->string('status')->default('issued')->index();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->timestamps();
            $table->unique(['software_license_id', 'installation_uuid'], 'license_grant_license_install_unique');
            $table->unique(['product_id', 'installation_uuid'], 'license_grant_product_install_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('license_installation_grants');
    }
};