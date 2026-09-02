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
        Schema::create('support_teams', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::table('support_tickets', function (Blueprint $table): void {
            $table->foreignId('support_team_id')->nullable()->after('product_id')->constrained('support_teams')->restrictOnDelete();
        });

        foreach (['technical', 'billing', 'license', 'deployment', 'training', 'general'] as $order => $slug) {
            $id = DB::table('support_teams')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'name' => Str::headline($slug),
                'slug' => $slug,
                'is_active' => true,
                'sort_order' => $order + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('support_tickets')->where('category', $slug)->update(['support_team_id' => $id]);
        }
    }

    public function down(): void
    {
        Schema::table('support_tickets', fn (Blueprint $table) => $table->dropConstrainedForeignId('support_team_id'));
        Schema::dropIfExists('support_teams');
    }
};
