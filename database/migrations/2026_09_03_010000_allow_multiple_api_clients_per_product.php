<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_clients', function (Blueprint $table): void {
            $table->unique(['product_id', 'name'], 'api_clients_product_name_unique');
            $table->dropUnique('api_clients_product_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('api_clients', function (Blueprint $table): void {
            $table->unique('product_id', 'api_clients_product_id_unique');
            $table->dropUnique('api_clients_product_name_unique');
        });
    }
};
