<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('subscription_number')->nullable()->unique();
            $table->string('status')->default('pending')->index();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable()->index();
            $table->timestamp('trial_ends_at')->nullable()->index();
            $table->timestamp('grace_ends_at')->nullable()->index();
            $table->boolean('auto_renew')->default(true);
            $table->decimal('price', 15, 2);
            $table->char('currency', 3);
            $table->string('billing_cycle');
            $table->json('metadata')->nullable();
            $table->timestamp('renewal_notice_sent_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['customer_id', 'product_id', 'status']);
        });

        Schema::create('subscription_changes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('subscription_id')->constrained()->restrictOnDelete();
            $table->string('change_type')->index();
            $table->foreignId('from_plan_id')->nullable()->constrained('plans')->restrictOnDelete();
            $table->foreignId('to_plan_id')->nullable()->constrained('plans')->restrictOnDelete();
            $table->decimal('credit', 15, 2)->default(0);
            $table->decimal('debit', 15, 2)->default(0);
            $table->decimal('net_amount', 15, 2)->default(0);
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('effective_at');
            $table->timestamps();
        });

        Schema::create('subscription_entitlements', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('subscription_id')->constrained()->restrictOnDelete();
            $table->string('feature_key');
            $table->string('feature_name');
            $table->boolean('enabled')->default(true);
            $table->string('limit_value')->nullable();
            $table->foreignId('source_plan_id')->constrained('plans')->restrictOnDelete();
            $table->timestamp('effective_from');
            $table->timestamp('effective_until')->nullable();
            $table->timestamps();
            $table->index(['subscription_id', 'feature_key', 'effective_until'], 'subscription_entitlements_active_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_entitlements');
        Schema::dropIfExists('subscription_changes');
        Schema::dropIfExists('subscriptions');
    }
};
