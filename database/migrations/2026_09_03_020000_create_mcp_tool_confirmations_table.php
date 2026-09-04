<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_tool_confirmations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('request_id')->nullable()->index();
            $table->foreignId('api_client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('api_client_credential_id')->nullable()->constrained()->nullOnDelete();
            $table->string('tool_name')->index();
            $table->string('target_type');
            $table->uuid('target_uuid')->index();
            $table->string('confirmed_by');
            $table->text('confirmation_note');
            $table->string('risk_level')->default('high')->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_tool_confirmations');
    }
};
