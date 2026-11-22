<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_assets', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('stamp_path')->nullable();
            $table->string('signature_path')->nullable();
        });

        DB::table('document_assets')->insert(['id' => 1]);
    }

    public function down(): void
    {
        Schema::dropIfExists('document_assets');
    }
};
