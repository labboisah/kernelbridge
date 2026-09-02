<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('code')->nullable()->unique()->after('public_id');
            $table->string('short_description')->nullable()->after('tagline');
            $table->text('hero_description')->nullable()->after('short_description');
            $table->string('seo_title')->nullable()->after('description');
            $table->string('seo_description', 320)->nullable()->after('seo_title');
            $table->string('logo')->nullable()->after('icon');
            $table->json('benefits')->nullable()->after('features');
            $table->json('workflow')->nullable()->after('benefits');
            $table->json('platform_components')->nullable()->after('workflow');
            $table->json('screenshots')->nullable()->after('platform_components');
            $table->json('plans')->nullable()->after('screenshots');
            $table->string('website_url')->nullable()->after('demo_url');
            $table->boolean('is_featured')->default(true)->index()->after('website_url');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropUnique(['code']);
            $table->dropIndex(['is_featured']);
            $table->dropColumn(['code', 'short_description', 'hero_description', 'seo_title', 'seo_description', 'logo', 'benefits', 'workflow', 'platform_components', 'screenshots', 'plans', 'website_url', 'is_featured']);
        });
    }
};
