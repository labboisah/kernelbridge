<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partner_applications', function (Blueprint $table): void {
            $table->string('partner_category', 30)->default('individual');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->decimal('organization_partner_commission_rate', 5, 2)->default(0);
        });
        DB::table('products')->update([
            'organization_partner_commission_rate' => DB::raw('partner_commission_rate'),
        ]);

        Schema::create('partner_organization_upgrades', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('partner_application_id')->constrained('partner_applications')->restrictOnDelete();
            $table->string('business_legal_name');
            $table->string('registration_number');
            $table->string('registration_country', 100);
            $table->string('business_type', 40);
            $table->date('incorporation_date')->nullable();
            $table->string('tax_id')->nullable();
            $table->string('website', 500)->nullable();
            $table->string('business_email');
            $table->string('business_phone', 40);
            $table->text('registered_address');
            $table->string('city');
            $table->string('state_region');
            $table->string('country', 100);
            $table->string('representative_name');
            $table->string('representative_title');
            $table->string('representative_email');
            $table->string('representative_phone', 40);
            $table->json('ownership_details');
            $table->text('business_activity');
            $table->text('partnership_goals');
            $table->string('status', 30)->default('submitted')->index();
            $table->text('review_note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_organization_upgrades');
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('organization_partner_commission_rate');
        });
        Schema::table('partner_applications', function (Blueprint $table): void {
            $table->dropColumn('partner_category');
        });
    }
};
