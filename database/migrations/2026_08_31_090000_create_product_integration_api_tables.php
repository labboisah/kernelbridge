<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_clients', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('product_id')->unique()->constrained()->restrictOnDelete();
            $table->string('name');
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedSmallInteger('rate_limit_per_minute')->default(120);
            $table->json('allowed_ips')->nullable();
            $table->json('scopes')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
        Schema::create('api_client_credentials', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('api_client_id')->constrained()->restrictOnDelete();
            $table->char('secret_hash', 64);
            $table->string('name');
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('api_request_logs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('request_id')->unique();
            $table->foreignId('api_client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('method', 10);
            $table->string('path');
            $table->string('ip_address', 45)->nullable()->index();
            $table->text('user_agent')->nullable();
            $table->unsignedSmallInteger('status_code');
            $table->unsignedInteger('duration_ms');
            $table->string('error_code')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
        Schema::create('software_licenses', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('subscription_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->char('key_lookup', 64)->unique();
            $table->string('key_hash');
            $table->string('status')->default('active')->index();
            $table->unsignedSmallInteger('max_activations')->default(1);
            $table->timestamp('expires_at')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
        Schema::create('license_activations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('software_license_id')->constrained()->restrictOnDelete();
            $table->char('device_fingerprint', 64);
            $table->string('device_name')->nullable();
            $table->string('status')->default('active')->index();
            $table->timestamp('activated_at');
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['software_license_id', 'device_fingerprint'], 'license_activation_device_unique');
        });
        Schema::create('demo_product_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('api_client_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('event_id');
            $table->string('event_type')->index();
            $table->timestamp('occurred_at');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['api_client_id', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_product_events');
        Schema::dropIfExists('license_activations');
        Schema::dropIfExists('software_licenses');
        Schema::dropIfExists('api_request_logs');
        Schema::dropIfExists('api_client_credentials');
        Schema::dropIfExists('api_clients');
    }
};
