<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->foreignId('partner_user_id')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::create('lead_stage_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status');
            $table->timestamp('entered_at');
            $table->index(['lead_id', 'entered_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_stage_history');
        Schema::table('leads', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('partner_user_id');
        });
    }
};
