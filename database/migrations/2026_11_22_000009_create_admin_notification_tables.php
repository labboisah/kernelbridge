<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_templates', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 120)->unique();
            $table->string('subject', 180);
            $table->text('message');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('admin_notifications', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('subject', 180);
            $table->text('message');
            $table->string('audience_type', 20);
            $table->string('audience_identifier');
            $table->text('audience_label');
            $table->json('channels');
            $table->unsignedInteger('recipient_count');
            $table->unsignedInteger('email_only_count');
            $table->unsignedInteger('unreachable_count');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_notifications');
        Schema::dropIfExists('notification_templates');
    }
};
