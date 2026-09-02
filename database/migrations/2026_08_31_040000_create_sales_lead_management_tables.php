<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('lead_number')->nullable()->unique();
            $table->string('name');
            $table->string('organization')->nullable()->index();
            $table->string('email')->index();
            $table->string('phone', 40)->nullable();
            $table->string('location')->nullable();
            $table->string('organization_type')->nullable()->index();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('number_of_users')->nullable();
            $table->string('source')->nullable()->index();
            $table->string('status')->default('new')->index();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->date('follow_up_date')->nullable()->index();
            $table->foreignId('converted_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->timestamp('converted_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('lead_activities', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type')->index();
            $table->string('description');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('lead_notes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->boolean('is_private')->default(true);
            $table->timestamps();
        });

        Schema::table('demo_requests', function (Blueprint $table): void {
            $table->foreignId('lead_id')->nullable()->after('public_id')->constrained()->nullOnDelete();
            $table->uuid('uuid')->nullable()->unique()->after('id');
            $table->string('organization')->nullable()->after('name');
            $table->string('location')->nullable()->after('phone');
            $table->string('organization_type')->nullable()->after('location');
            $table->unsignedInteger('number_of_users')->nullable()->after('organization_type');
            $table->date('preferred_demo_date')->nullable()->after('number_of_users');
            $table->time('preferred_demo_time')->nullable()->after('preferred_demo_date');
            $table->dateTime('scheduled_at')->nullable()->after('preferred_demo_time');
            $table->string('source')->nullable()->after('message');
            $table->foreignId('assigned_to')->nullable()->after('source')->constrained('users')->nullOnDelete();
        });

        DB::table('demo_requests')->update(['status' => 'pending']);
        DB::table('demo_requests')->orderBy('id')->eachById(function (object $demoRequest): void {
            DB::table('demo_requests')->where('id', $demoRequest->id)->update([
                'uuid' => (string) Str::uuid(),
                'organization' => $demoRequest->company,
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('demo_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('lead_id');
            $table->dropConstrainedForeignId('assigned_to');
            $table->dropColumn(['uuid', 'organization', 'location', 'organization_type', 'number_of_users', 'preferred_demo_date', 'preferred_demo_time', 'scheduled_at', 'source']);
        });
        Schema::dropIfExists('lead_notes');
        Schema::dropIfExists('lead_activities');
        Schema::dropIfExists('leads');
    }
};
