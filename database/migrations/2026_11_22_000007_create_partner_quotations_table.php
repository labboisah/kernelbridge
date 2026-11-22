<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_quotations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('quotation_number')->nullable()->unique();
            $table->foreignId('partner_user_id')->constrained('users');
            $table->foreignId('prospective_customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
            $table->string('partner_name');
            $table->string('recipient_name');
            $table->string('recipient_organization')->nullable();
            $table->string('recipient_email')->nullable();
            $table->string('recipient_phone', 40)->nullable();
            $table->string('recipient_location')->nullable();
            $table->string('product_name');
            $table->string('plan_name');
            $table->string('billing_cycle');
            $table->string('currency', 3);
            $table->text('description');
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 15, 2);
            $table->decimal('vat_rate', 5, 2);
            $table->decimal('subtotal', 15, 2);
            $table->decimal('tax', 15, 2);
            $table->decimal('total', 15, 2);
            $table->date('issue_date');
            $table->date('expires_at')->nullable();
            $table->text('notes')->nullable();
            $table->text('terms')->nullable();
            $table->timestamps();
            $table->index(['partner_user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_quotations');
    }
};
