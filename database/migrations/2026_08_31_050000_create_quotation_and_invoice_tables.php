<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotations', function (Blueprint $t): void {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->string('quotation_number')->nullable()->unique();
            $t->foreignId('customer_id')->constrained()->restrictOnDelete();
            $t->date('issue_date');
            $t->date('expiry_date');
            $t->decimal('subtotal', 15, 2);
            $t->decimal('discount', 15, 2)->default(0);
            $t->decimal('tax', 15, 2)->default(0);
            $t->decimal('total', 15, 2);
            $t->char('currency', 3);
            $t->string('status')->default('draft')->index();
            $t->text('notes')->nullable();
            $t->text('terms')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('accepted_at')->nullable();
            $t->timestamp('rejected_at')->nullable();
            $t->timestamps();
        });
        Schema::create('quotation_items', function (Blueprint $t): void {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('quotation_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
            $t->string('product')->nullable();
            $t->string('plan')->nullable();
            $t->text('description');
            $t->decimal('quantity', 12, 2);
            $t->decimal('unit_price', 15, 2);
            $t->decimal('discount', 15, 2)->default(0);
            $t->decimal('tax', 15, 2)->default(0);
            $t->decimal('total', 15, 2);
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->timestamps();
        });
        Schema::create('invoices', function (Blueprint $t): void {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->string('invoice_number')->nullable()->unique();
            $t->foreignId('quotation_id')->nullable()->unique()->constrained()->nullOnDelete();
            $t->foreignId('customer_id')->constrained()->restrictOnDelete();
            $t->date('issue_date');
            $t->date('due_date');
            $t->decimal('subtotal', 15, 2);
            $t->decimal('discount', 15, 2)->default(0);
            $t->decimal('tax', 15, 2)->default(0);
            $t->decimal('total', 15, 2);
            $t->char('currency', 3);
            $t->string('status')->default('draft')->index();
            $t->text('notes')->nullable();
            $t->text('terms')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
        Schema::create('invoice_items', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
            $t->string('product')->nullable();
            $t->string('plan')->nullable();
            $t->text('description');
            $t->decimal('quantity', 12, 2);
            $t->decimal('unit_price', 15, 2);
            $t->decimal('discount', 15, 2)->default(0);
            $t->decimal('tax', 15, 2)->default(0);
            $t->decimal('total', 15, 2);
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('quotation_items');
        Schema::dropIfExists('quotations');
    }
};
