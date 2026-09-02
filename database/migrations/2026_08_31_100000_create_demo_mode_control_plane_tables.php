<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_profiles', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('product_id')->unique()->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('public_demo_url')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('requires_access_token')->default(false);
            foreach (['allow_create', 'allow_edit', 'allow_delete', 'allow_export', 'allow_email', 'allow_sms', 'allow_payment', 'allow_configuration', 'allow_user_management'] as $restriction) {
                $table->boolean($restriction)->default(false);
            }
            $table->json('protected_roles')->nullable();
            $table->json('protected_accounts')->nullable();
            $table->json('configuration')->nullable();
            $table->unsignedInteger('config_version')->default(1);
            $table->boolean('reset_enabled')->default(false);
            $table->string('reset_schedule')->nullable();
            $table->text('reset_instructions')->nullable();
            $table->unsignedInteger('reset_instruction_version')->default(1);
            $table->timestamp('next_reset_at')->nullable()->index();
            $table->timestamp('last_reset_acknowledged_at')->nullable();
            $table->unsignedInteger('last_acknowledged_version')->nullable();
            $table->timestamps();
        });
        Schema::create('demo_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('demo_profile_id')->constrained()->restrictOnDelete();
            $table->char('token_lookup', 64)->unique();
            $table->string('token_hash');
            $table->string('label')->nullable();
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('uses')->default(0);
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->timestamps();
        });
        Schema::create('demo_sessions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('demo_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('demo_access_token_id')->nullable()->constrained()->restrictOnDelete();
            $table->char('anonymous_identifier_hash', 64);
            $table->string('organization_identifier')->nullable();
            $table->timestamp('started_at')->index();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('entry_url')->nullable();
            $table->string('referrer')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['product_id', 'started_at']);
        });
        Schema::create('demo_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('demo_session_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('external_event_id');
            $table->string('event_type')->index();
            $table->string('page')->nullable()->index();
            $table->string('feature')->nullable()->index();
            $table->string('cta')->nullable()->index();
            $table->timestamp('occurred_at')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['demo_session_id', 'external_event_id'], 'demo_session_event_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_events');
        Schema::dropIfExists('demo_sessions');
        Schema::dropIfExists('demo_access_tokens');
        Schema::dropIfExists('demo_profiles');
    }
};
