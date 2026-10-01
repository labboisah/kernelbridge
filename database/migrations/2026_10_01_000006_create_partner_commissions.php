<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', fn (Blueprint $t) => $t->decimal('partner_commission_rate', 5, 2)->default(0));
        Schema::create('partner_commissions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('partner_user_id')->constrained('users');
            $t->foreignId('invoice_id')->constrained();
            $t->foreignId('product_id')->constrained();
            $t->decimal('rate', 5, 2);
            $t->decimal('base_amount', 14, 2);
            $t->decimal('amount', 14, 2);
            $t->string('currency', 3);
            $t->string('status')->default('earned');
            $t->timestamp('earned_at');
            $t->timestamp('reversed_at')->nullable();
            $t->timestamps();
            $t->unique(['invoice_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_commissions');
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn('partner_commission_rate'));
    }
};
