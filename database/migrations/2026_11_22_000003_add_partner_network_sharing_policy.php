<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_settings', function (Blueprint $table): void {
            if (! Schema::hasColumn('billing_settings', 'partner_network_sharing_enabled')) {
                $table->boolean('partner_network_sharing_enabled')->default(false);
            }
            if (! Schema::hasColumn('billing_settings', 'partner_network_min_active_clients')) {
                $table->unsignedInteger('partner_network_min_active_clients')->default(1);
            }
            if (! Schema::hasColumn('billing_settings', 'partner_network_pool_rate')) {
                $table->decimal('partner_network_pool_rate', 5, 2)->default(0);
            }
            if (! Schema::hasColumn('billing_settings', 'partner_network_qualified_weight')) {
                $table->decimal('partner_network_qualified_weight', 8, 2)->default(1);
            }
            if (! Schema::hasColumn('billing_settings', 'partner_network_premium_weight')) {
                $table->decimal('partner_network_premium_weight', 8, 2)->default(2);
            }
            if (! Schema::hasColumn('billing_settings', 'partner_network_elite_weight')) {
                $table->decimal('partner_network_elite_weight', 8, 2)->default(3);
            }
        });

        Schema::create('partner_network_commissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('partner_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('source_partner_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('source_customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('earning_sequence')->default(1);
            $table->string('partner_level', 30);
            $table->decimal('level_weight', 10, 2);
            $table->decimal('pool_rate', 5, 2);
            $table->decimal('payment_base_amount', 14, 2);
            $table->decimal('platform_amount', 14, 2);
            $table->decimal('pool_amount', 14, 2);
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3);
            $table->string('status', 30)->default('earned');
            $table->timestamp('earned_at');
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['partner_user_id', 'invoice_id', 'product_id', 'earning_sequence'],
                'pnc_partner_invoice_product_seq_unique',
            );
            $table->index(['partner_user_id', 'status'], 'pnc_partner_status_idx');
            $table->index(['source_partner_user_id', 'invoice_id'], 'pnc_source_partner_invoice_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_network_commissions');
        Schema::table('billing_settings', function (Blueprint $table): void {
            $table->dropColumn([
                'partner_network_sharing_enabled',
                'partner_network_min_active_clients',
                'partner_network_pool_rate',
                'partner_network_qualified_weight',
                'partner_network_premium_weight',
                'partner_network_elite_weight',
            ]);
        });
    }
};
