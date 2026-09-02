<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->string('billing_cycle')->index();
            $table->decimal('price', 15, 2)->nullable();
            $table->char('currency', 3)->default('USD');
            $table->decimal('setup_fee', 15, 2)->default(0);
            $table->unsignedSmallInteger('trial_days')->default(0);
            $table->unsignedSmallInteger('grace_period_days')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('is_public')->default(true)->index();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['product_id', 'slug']);
        });

        Schema::create('plan_features', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->string('feature_key');
            $table->string('feature_name');
            $table->boolean('enabled')->default(true);
            $table->string('limit_value')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['plan_id', 'feature_key']);
        });

        Schema::create('plan_limits', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->string('limit_key');
            $table->string('limit_name');
            $table->string('limit_value')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['plan_id', 'limit_key']);
        });

        Schema::create('product_plan', function (Blueprint $table): void {
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['product_id', 'plan_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_plan');
        Schema::dropIfExists('plan_limits');
        Schema::dropIfExists('plan_features');
        Schema::dropIfExists('plans');
    }
};
