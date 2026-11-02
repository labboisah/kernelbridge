<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->unsignedBigInteger('entitlement_revision')->default(1);
            $table->timestamp('entitlement_revision_checked_at')->nullable();
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->timestamp('licensing_mode_changed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('licensing_mode_changed_at');
        });

        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropColumn(['entitlement_revision', 'entitlement_revision_checked_at']);
        });
    }
};
