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
        Schema::table('products', function (Blueprint $table): void {
            $table->uuid('uuid')->nullable()->unique()->after('id');
            $table->string('category')->nullable()->index()->after('description');
            $table->string('cover_image')->nullable()->after('logo');
            $table->json('metadata')->nullable()->after('is_featured');
        });

        DB::table('products')->whereNull('uuid')->orderBy('id')->eachById(function (object $product): void {
            DB::table('products')->where('id', $product->id)->update(['uuid' => (string) Str::uuid()]);
        });

        Schema::create('product_features', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('icon')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('product_media', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('type')->default('screenshot')->index();
            $table->string('title')->nullable();
            $table->string('url', 2048);
            $table->string('alt_text')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('product_links', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('type')->index();
            $table->string('label');
            $table->string('url', 2048);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_links');
        Schema::dropIfExists('product_media');
        Schema::dropIfExists('product_features');

        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn(['uuid', 'category', 'cover_image', 'metadata']);
        });
    }
};
