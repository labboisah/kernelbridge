<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $t): void {
            $t->decimal('amount_paid', 15, 2)->default(0)->after('total');
            $t->decimal('balance', 15, 2)->default(0)->after('amount_paid');
            $t->string('payment_reference')->nullable()->after('notes');
            $t->timestamp('issued_at')->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->timestamp('cancelled_at')->nullable();
        });
        Schema::create('invoice_payments', function (Blueprint $t): void {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $t->decimal('amount', 15, 2);
            $t->char('currency', 3);
            $t->string('reference')->unique();
            $t->string('method')->nullable();
            $t->string('status')->default('successful')->index();
            $t->timestamp('paid_at');
            $t->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->json('metadata')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_payments');
        Schema::table('invoices', function (Blueprint $t): void {
            $t->dropColumn(['amount_paid', 'balance', 'payment_reference', 'issued_at', 'paid_at', 'cancelled_at']);
        });
    }
};
