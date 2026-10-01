<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('developer_commission_rate', 5, 2)->default(0);
            $table->decimal('platform_commission_rate', 5, 2)->default(100);
            $table->decimal('staff_commission_rate', 5, 2)->default(0);
        });
        DB::table('products')->update(['platform_commission_rate' => DB::raw('100 - partner_commission_rate')]);
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn(['developer_commission_rate', 'platform_commission_rate', 'staff_commission_rate']));
    }
};
