<?php

namespace Tests\Feature\Admin;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\User;
use Database\Seeders\ProductSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class ExecutiveDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('dashboard.cache_store', 'array');
        $this->seed([RoleAndPermissionSeeder::class, ProductSeeder::class]);
    }

    public function test_admin_receives_backend_calculated_dashboard_report(): void
    {
        Customer::query()->create([
            'uuid' => (string) Str::uuid(), 'customer_number' => 'KBT-CUS-999999',
            'type' => CustomerType::Organization, 'name' => 'Dashboard Customer',
            'status' => CustomerStatus::Active,
        ]);

        $this->actingAs($this->admin())->get(route('admin.dashboard', ['range' => 'year']))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Dashboard')
            ->where('metrics.totalCustomers', 1)
            ->where('metrics.activeCustomers', 1)
            ->has('metrics.activeSubscriptions')
            ->has('metrics.revenueThisMonth')
            ->has('productMetrics', 5)
            ->has('charts.monthlyRevenue')
            ->has('charts.subscriptionsByProduct', 5)
            ->has('charts.leadsByStatus')
            ->has('charts.invoiceStatus')
            ->has('charts.customerGrowth')
            ->where('filters.range', 'year'));
    }

    public function test_custom_dashboard_dates_are_validated(): void
    {
        $this->actingAs($this->admin())->get(route('admin.dashboard', [
            'range' => 'custom', 'from' => '2026-08-31', 'to' => '2026-01-01',
        ]))->assertSessionHasErrors('to');
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(UserRole::SuperAdmin->value);

        return $user;
    }
}
