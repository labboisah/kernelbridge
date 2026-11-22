<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partner_quotations', function (Blueprint $table): void {
            $table->json('plan_modules')->nullable();
            $table->json('plan_features')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('partner_quotations', function (Blueprint $table): void {
            $table->dropColumn(['plan_modules', 'plan_features']);
        });
    }
};
