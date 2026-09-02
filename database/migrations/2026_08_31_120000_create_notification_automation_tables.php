<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        Schema::create('automation_notifications', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->string('type')->index();
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->timestamp('processed_at');
            $table->json('metadata')->nullable();
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_notifications');
        Schema::dropIfExists('notifications');
    }
};
