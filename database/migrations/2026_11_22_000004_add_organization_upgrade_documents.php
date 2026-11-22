<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partner_organization_upgrades', function (Blueprint $table): void {
            $table->string('registration_proof_path')->nullable();
            $table->string('owner_identity_path')->nullable();
            $table->string('address_proof_path')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('partner_organization_upgrades', function (Blueprint $table): void {
            $table->dropColumn(['registration_proof_path', 'owner_identity_path', 'address_proof_path']);
        });
    }
};
