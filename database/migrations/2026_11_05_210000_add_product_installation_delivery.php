<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('repository_url', 512)->nullable();
            $table->string('repository_visibility', 16)->nullable();
            $table->string('github_installation_id', 32)->nullable();
            $table->string('stable_release_version', 64)->nullable();
            $table->string('stable_release_tag', 120)->nullable();
            $table->longText('installation_guide_markdown')->nullable();
        });

        Schema::create('product_releases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('version', 64);
            $table->string('tag', 120);
            $table->string('status', 20)->default('building')->index();
            $table->string('storage_path', 512)->nullable();
            $table->char('sha256', 64)->nullable();
            $table->unsignedBigInteger('archive_size')->nullable();
            $table->string('failure_code', 64)->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('build_started_at')->nullable();
            $table->timestamp('built_at')->nullable();
            $table->timestamps();
            $table->unique(['product_id', 'version', 'tag']);
        });

        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->foreignId('product_release_id')->nullable()->constrained('product_releases')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('product_release_id');
        });
        Schema::dropIfExists('product_releases');
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn([
                'repository_url', 'repository_visibility', 'github_installation_id',
                'stable_release_version', 'stable_release_tag', 'installation_guide_markdown',
            ]);
        });
    }
};
