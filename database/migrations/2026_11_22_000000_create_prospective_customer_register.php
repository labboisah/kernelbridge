<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->string('email')->nullable()->change();
        });

        Schema::create('prospective_customers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('partner_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('organization')->nullable()->index();
            $table->string('email')->nullable()->index();
            $table->string('phone', 40)->nullable()->index();
            $table->string('location')->nullable();
            $table->string('organization_type')->nullable()->index();
            $table->unsignedInteger('number_of_users')->nullable();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->date('follow_up_date')->nullable()->index();
            $table->text('initial_notes')->nullable();
            $table->string('status')->default('prospective')->index();
            $table->timestamp('contacted_at')->nullable();
            $table->timestamp('interested_at')->nullable();
            $table->timestamp('consent_at')->nullable();
            $table->string('consent_method')->nullable();
            $table->string('consented_by_name')->nullable();
            $table->text('consent_details')->nullable();
            $table->text('contact_notes')->nullable();
            $table->foreignId('converted_lead_id')->nullable()->unique()->constrained('leads')->nullOnDelete();
            $table->timestamps();

            $table->index(['partner_user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prospective_customers');
    }
};
