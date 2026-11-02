<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('licensing_mode')->default('legacy')->index();
            $table->string('runtime_type')->default('unknown')->index();
            $table->unsignedInteger('licensing_policy_revision')->default(1);
        });

        Schema::create('license_activation_codes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('software_license_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->char('code_lookup', 64)->unique();
            $table->string('code_hash');
            $table->string('status')->default('issued')->index();
            $table->timestamp('expires_at')->index();
            $table->timestamp('redeemed_at')->nullable();
            $table->uuid('redeemed_installation_uuid')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('replacement_of_id')->nullable()->constrained('license_activation_codes')->nullOnDelete();
            $table->foreignId('replaced_by_id')->nullable()->constrained('license_activation_codes')->nullOnDelete();
            $table->timestamps();

            $table->index(['software_license_id', 'status', 'expires_at'], 'activation_codes_license_status_expiry_idx');
        });

        Schema::create('license_installations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('software_license_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->uuid('installation_uuid');
            $table->char('credential_hash', 64);
            $table->text('credential_encrypted')->nullable();
            $table->string('device_name')->nullable();
            $table->string('runtime_type')->default('unknown');
            $table->string('status')->default('active')->index();
            $table->timestamp('activated_at');
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['product_id', 'installation_uuid'], 'license_installation_product_uuid_unique');
            $table->index(['software_license_id', 'status', 'expires_at'], 'license_installations_license_status_expiry_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('license_installations');
        Schema::dropIfExists('license_activation_codes');

        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex(['licensing_mode']);
            $table->dropIndex(['runtime_type']);
            $table->dropColumn(['licensing_mode', 'runtime_type', 'licensing_policy_revision']);
        });
    }
};
