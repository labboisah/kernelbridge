<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_settings', function (Blueprint $t) {
            $t->id();
            $t->decimal('vat_rate', 5, 2)->default(7.5);
            $t->timestamps();
        });
        DB::table('billing_settings')->insert(['id' => 1, 'vat_rate' => '7.50', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_settings');
    }
};
