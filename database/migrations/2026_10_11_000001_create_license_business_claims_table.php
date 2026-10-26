<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('license_business_claims', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('software_license_id')->unique('license_claim_license_unique')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->uuid('business_uuid');
            $table->timestamps();
            $table->unique(['product_id', 'business_uuid'], 'license_claim_business_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('license_business_claims');
    }
};