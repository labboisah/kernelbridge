<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hosting_settings', function (Blueprint $t): void {
            $t->id();
            $t->boolean('enabled')->default(true);
            $t->string('currency', 3);
            $t->decimal('monthly_price', 18, 2);
            $t->timestamps();
        });
        DB::table('hosting_settings')->insert(['id' => 1, 'enabled' => true, 'currency' => 'NGN', 'monthly_price' => '20000.00', 'created_at' => now(), 'updated_at' => now()]);
        Schema::table('api_clients', fn (Blueprint $t) => $t->foreignId('subscription_id')->nullable()->constrained()->restrictOnDelete());
        Schema::create('client_setup_bundles', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('subscription_id')->unique()->constrained()->cascadeOnDelete();
            $t->text('secrets');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_setup_bundles');
        Schema::table('api_clients', fn (Blueprint $t) => $t->dropConstrainedForeignId('subscription_id'));
        Schema::dropIfExists('hosting_settings');
    }
};
