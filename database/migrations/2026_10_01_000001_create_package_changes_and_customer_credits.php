<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_package_orders', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('subscription_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('kind'); // addition or renewal
            $table->string('status')->default('pending');
            $table->json('snapshot');
            $table->timestamp('quoted_at');
            $table->timestamp('applied_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['subscription_id', 'status']);
        });
        Schema::create('customer_credit_entries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('reference')->unique();
            $table->char('currency', 3);
            $table->decimal('amount', 18, 2); // positive credit, negative use
            $table->string('description');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['customer_id', 'currency']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_credit_entries');
        Schema::dropIfExists('subscription_package_orders');
    }
};
