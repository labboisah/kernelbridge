<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partner_applications', function (Blueprint $table): void {
            $table->text('background_knowledge')->nullable();
            $table->text('digital_skills')->nullable();
            $table->text('installation_experience')->nullable();
            $table->text('networking_experience')->nullable();
            $table->text('business_skills')->nullable();
            $table->text('management_skills')->nullable();
            $table->string('photo_path')->nullable();
        });

        Schema::create('partner_bank_details', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('bank_name');
            $table->text('account_name');
            $table->text('account_number');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_bank_details');
        Schema::table('partner_applications', function (Blueprint $table): void {
            $table->dropColumn(['background_knowledge', 'digital_skills', 'installation_experience', 'networking_experience', 'business_skills', 'management_skills', 'photo_path']);
        });
    }
};
