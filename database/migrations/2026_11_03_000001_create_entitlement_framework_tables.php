<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entitlement_catalog_revisions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('schema_version');
            $table->unsignedBigInteger('catalog_revision');
            $table->json('catalog');
            $table->char('catalog_digest', 64);
            $table->timestamp('published_at');
            $table->timestamps();
            $table->unique(['product_id', 'catalog_revision'], 'ent_catalog_product_revision_unique');
        });

        Schema::create('entitlement_plan_mappings', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->foreignId('entitlement_catalog_revision_id')
                ->constrained('entitlement_catalog_revisions', 'id', 'ent_plan_catalog_fk')
                ->restrictOnDelete();
            $table->unsignedBigInteger('source_revision');
            $table->json('grants');
            $table->char('mapping_digest', 64);
            $table->timestamp('published_at');
            $table->timestamps();
            $table->unique(['plan_id', 'source_revision'], 'ent_plan_source_revision_unique');
            $table->index(['plan_id', 'entitlement_catalog_revision_id', 'source_revision'], 'ent_plan_mapping_lookup_idx');
        });

        Schema::create('entitlement_source_grants', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('software_license_id')
                ->constrained('software_licenses', 'id', 'ent_source_license_fk')
                ->restrictOnDelete();
            $table->foreignId('license_platform_account_link_id')
                ->nullable()
                ->constrained('license_platform_account_links', 'id', 'ent_source_link_fk')
                ->nullOnDelete();
            $table->foreignId('entitlement_catalog_revision_id')
                ->constrained('entitlement_catalog_revisions', 'id', 'ent_source_catalog_fk')
                ->restrictOnDelete();
            $table->string('source', 32);
            $table->unsignedBigInteger('source_revision');
            $table->string('idempotency_key', 128);
            $table->json('grants');
            $table->char('payload_digest', 64);
            $table->timestamp('accepted_at');
            $table->timestamps();
            $table->unique(['software_license_id', 'source', 'source_revision'], 'ent_source_license_revision_unique');
            $table->unique(['software_license_id', 'idempotency_key'], 'ent_source_license_idempotency_unique');
            $table->index(['product_id', 'source', 'source_revision'], 'ent_source_product_revision_idx');
            $table->index(['software_license_id', 'source', 'source_revision'], 'ent_source_lookup_idx');
        });

        Schema::create('entitlement_framework_audits', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('software_license_id')
                ->nullable()
                ->constrained('software_licenses', 'id', 'ent_audit_license_fk')
                ->nullOnDelete();
            $table->foreignId('license_platform_account_link_id')
                ->nullable()
                ->constrained('license_platform_account_links', 'id', 'ent_audit_link_fk')
                ->nullOnDelete();
            $table->string('source', 32);
            $table->string('operation', 48);
            $table->string('outcome', 16);
            $table->unsignedBigInteger('catalog_revision')->nullable();
            $table->unsignedBigInteger('source_revision')->nullable();
            $table->string('idempotency_key', 128)->nullable();
            $table->char('payload_digest', 64)->nullable();
            $table->string('error_code', 100)->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamps();
            $table->index(['product_id', 'source', 'created_at'], 'ent_audit_product_source_idx');
            $table->index(['software_license_id', 'source', 'source_revision'], 'ent_audit_license_revision_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entitlement_framework_audits');
        Schema::dropIfExists('entitlement_source_grants');
        Schema::dropIfExists('entitlement_plan_mappings');
        Schema::dropIfExists('entitlement_catalog_revisions');
    }
};
