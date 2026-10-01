<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->decimal('vat_commission_rate', 5, 2)->default(7.5);
            $t->decimal('platform_commission_rate', 5, 2)->default(92.5)->change();
        });
        $vat = (float) DB::table('billing_settings')->where('id', 1)->value('vat_rate');
        DB::table('products')->orderBy('id')->chunkById(100, function ($products) use ($vat) {
            foreach ($products as $product) {
                $remaining = (int) round($vat * 100);
                $data = ['vat_commission_rate' => $vat];
                foreach (['platform_commission_rate', 'staff_commission_rate', 'developer_commission_rate', 'partner_commission_rate'] as $field) {
                    $points = (int) round($product->$field * 100);
                    $take = min($remaining, $points);
                    $remaining -= $take;
                    $data[$field] = ($points - $take) / 100;
                }
                DB::table('products')->where('id', $product->id)->update($data);
            }
        });
        Schema::create('product_commission_accruals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('invoice_id')->constrained();
            $t->foreignId('product_id')->constrained();
            $t->string('currency', 3);
            $t->decimal('base_amount', 14, 2);
            $t->json('rates');
            foreach (['vat', 'developer', 'platform', 'staff', 'agent'] as $party) {
                $t->decimal($party.'_amount', 14, 2);
            }
            $t->string('status')->default('earned');
            $t->timestamps();
            $t->unique(['invoice_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_commission_accruals');
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn('vat_commission_rate'));
    }
};
