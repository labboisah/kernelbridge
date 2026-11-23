<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_notifications', function (Blueprint $table): void {
            $table->boolean('delivery_tracking')->default(false);
        });
        Schema::create('admin_notification_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('admin_notification_id')->constrained('admin_notifications')->cascadeOnDelete();
            $table->string('channel', 20);
            $table->string('status', 20)->default('pending');
            $table->timestamps();
            $table->index(['admin_notification_id', 'channel', 'status'], 'admin_notification_delivery_progress');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_notification_deliveries');
        Schema::table('admin_notifications', function (Blueprint $table): void {
            $table->dropColumn('delivery_tracking');
        });
    }
};
