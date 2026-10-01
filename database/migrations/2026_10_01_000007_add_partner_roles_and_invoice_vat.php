<?php

use App\Enums\UserRole;
use App\Models\PartnerApplication;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['investor', 'agent', 'developer', 'customer'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        PartnerApplication::with('user')->whereIn('type', ['investor', 'sales_agent', 'developer'])->chunkById(100, function ($applications) {
            foreach ($applications as $application) {
                $application->user?->assignRole(UserRole::forPartnerType($application->type));
            }
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Schema::table('invoices', fn (Blueprint $t) => $t->decimal('vat_rate', 5, 2)->nullable());
    }

    public function down(): void
    {
        Schema::table('invoices', fn (Blueprint $t) => $t->dropColumn('vat_rate'));
    }
};
