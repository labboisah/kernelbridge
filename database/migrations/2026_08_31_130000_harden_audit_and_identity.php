<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->string('action')->nullable()->index()->after('event');
            $table->string('entity_type')->nullable()->index()->after('actor_id');
            $table->string('entity_id')->nullable()->index()->after('entity_type');
            $table->json('before')->nullable()->after('entity_id');
            $table->json('after')->nullable()->after('before');
            $table->index(['entity_type', 'entity_id', 'created_at']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->text('two_factor_secret')->nullable()->after('password');
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']));
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->dropIndex(['entity_type', 'entity_id', 'created_at']);
            $table->dropColumn(['action', 'entity_type', 'entity_id', 'before', 'after']);
        });
    }
};
