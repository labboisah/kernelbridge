<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_settings', function (Blueprint $table): void {
            $table->decimal('partner_point_value', 14, 2)->default(500);
        });

        Schema::create('partner_point_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('partner_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('prospective_customer_id')->nullable()->constrained('prospective_customers')->restrictOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained('leads')->restrictOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->restrictOnDelete();
            $table->foreignId('invoice_payment_id')->nullable()->constrained('invoice_payments')->restrictOnDelete();
            $table->string('event_type');
            $table->integer('points');
            $table->decimal('point_value', 14, 2)->nullable();
            $table->decimal('amount', 14, 2)->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('status')->default('earned');
            $table->timestamps();

            $table->unique(['prospective_customer_id', 'event_type']);
            $table->unique(['lead_id', 'event_type']);
            $table->unique(['payment_id', 'event_type']);
            $table->unique(['invoice_payment_id', 'event_type']);
            $table->index(['partner_user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_point_entries');
        Schema::table('billing_settings', function (Blueprint $table): void {
            $table->dropColumn('partner_point_value');
        });
    }
};
