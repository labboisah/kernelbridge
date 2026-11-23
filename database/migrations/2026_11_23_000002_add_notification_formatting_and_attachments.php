<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_notifications', fn (Blueprint $table) => $table->string('message_format', 10)->default('plain'));
        Schema::table('notification_templates', fn (Blueprint $table) => $table->string('message_format', 10)->default('plain'));
        Schema::create('admin_notification_attachments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('admin_notification_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_notification_attachments');
        Schema::table('notification_templates', fn (Blueprint $table) => $table->dropColumn('message_format'));
        Schema::table('admin_notifications', fn (Blueprint $table) => $table->dropColumn('message_format'));
    }
};
