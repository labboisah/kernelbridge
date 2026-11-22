<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partner_organization_upgrades', function (Blueprint $table): void {
            $table->string('office_photo_one_path')->nullable();
            $table->string('office_photo_two_path')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('partner_organization_upgrades', function (Blueprint $table): void {
            $table->dropColumn(['office_photo_one_path', 'office_photo_two_path']);
        });
    }
};
