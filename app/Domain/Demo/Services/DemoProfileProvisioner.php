<?php

namespace App\Domain\Demo\Services;

use App\Enums\ProductStatus;
use App\Models\DemoProfile;
use App\Models\Product;
use Illuminate\Support\Collection;

final class DemoProfileProvisioner
{
    public function provisionActiveProducts(): Collection
    {
        Product::query()->where('status', ProductStatus::Active)->each(
            fn (Product $product) => $this->provision($product),
        );

        return DemoProfile::query()->with('product:id,uuid,code,name')->withCount('sessions')
            ->whereHas('product', fn ($query) => $query->where('status', ProductStatus::Active))
            ->orderBy('name')->get();
    }

    public function provision(Product $product): DemoProfile
    {
        return DemoProfile::query()->firstOrCreate(['product_id' => $product->id], [
            'name' => $product->name.' public demo', 'public_demo_url' => $product->demo_url,
            'is_active' => true, 'requires_access_token' => false,
            'allow_create' => true, 'allow_edit' => true, 'allow_delete' => false,
            'allow_export' => false, 'allow_email' => false, 'allow_sms' => false,
            'allow_payment' => false, 'allow_configuration' => false, 'allow_user_management' => false,
            'protected_roles' => ['super_admin', 'demo_admin'],
            'protected_accounts' => ['demo-admin@kernelbridge.com'],
            'configuration' => ['email_mode' => 'sink', 'sms_mode' => 'simulation', 'payment_mode' => 'simulation', 'tenant_isolation_required' => true],
            'reset_enabled' => true, 'reset_schedule' => '0 */6 * * *',
            'reset_instructions' => 'Run the product-owned demo reset command to restore the approved fixture snapshot. Never accept database connection details or remote mutation commands from KernelBridge.',
            'reset_instruction_version' => 1,
        ]);
    }
}
