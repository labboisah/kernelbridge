<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\ProductSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class CustomerManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleAndPermissionSeeder::class, ProductSeeder::class]);
    }

    public function test_administrator_can_manage_customer_relationships_and_audit_history(): void
    {
        $admin = $this->userWithRole(UserRole::Admin);
        $products = Product::query()->whereIn('code', ['EDUNEXA', 'ALIGNEX', 'MARKETLENS', 'STAYCONTROL'])->pluck('id')->all();
        $payload = $this->payload($products, $admin->id);

        $this->actingAs($admin)->post(route('admin.customers.store'), $payload)->assertRedirect();
        $customer = Customer::query()->where('name', 'ABC Academy')->firstOrFail();
        $this->assertSame('KBT-CUS-000001', $customer->customer_number);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $customer->uuid);
        $this->assertCount(1, $customer->contacts);
        $this->assertCount(1, $customer->addresses);
        $this->assertCount(4, $customer->products);
        $this->assertDatabaseHas('audit_logs', ['event' => 'customer.created', 'actor_id' => $admin->id]);

        $this->actingAs($admin)->post(route('admin.customers.notes.store', $customer), ['body' => 'Implementation discovery completed.', 'is_private' => true])->assertRedirect();
        $this->assertDatabaseHas('customer_notes', ['customer_id' => $customer->id, 'author_id' => $admin->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'customer.note_added', 'actor_id' => $admin->id]);

        $payload['name'] = 'ABC Academy Group';
        $payload['status'] = 'active';
        $payload['product_ids'] = array_slice($products, 0, 2);
        $this->actingAs($admin)->put(route('admin.customers.update', $customer), $payload)->assertRedirect(route('admin.customers.show', $customer));
        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'name' => 'ABC Academy Group', 'status' => 'active']);
        $this->assertCount(2, $customer->fresh()->products);
        $this->assertDatabaseHas('audit_logs', ['event' => 'customer.updated', 'actor_id' => $admin->id]);

        $this->actingAs($admin)->delete(route('admin.customers.destroy', $customer))->assertRedirect(route('admin.customers.index'));
        $this->assertDatabaseMissing('customers', ['id' => $customer->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'customer.deleted', 'actor_id' => $admin->id]);
    }

    public function test_customer_index_supports_search_and_filters(): void
    {
        $admin = $this->userWithRole(UserRole::Admin);
        $sales = $this->userWithRole(UserRole::Sales);
        $this->actingAs($admin)->post(route('admin.customers.store'), $this->payload([], $sales->id));
        $other = $this->payload([], $admin->id);
        $other['name'] = 'Jane Doe';
        $other['email'] = 'jane@example.com';
        $other['type'] = 'individual';
        $other['status'] = 'prospect';
        $other['source'] = 'Referral';
        $this->actingAs($admin)->post(route('admin.customers.store'), $other);

        $this->actingAs($admin)->get(route('admin.customers.index', ['search' => 'ABC', 'status' => 'lead', 'type' => 'organization', 'source' => 'Website', 'assigned_to' => $sales->id]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Customers/Index')->has('customers.data', 1)
            ->where('customers.data.0.name', 'ABC Academy')
            ->where('filters.status', 'lead'));
    }

    public function test_every_requested_customer_detail_tab_loads(): void
    {
        $admin = $this->userWithRole(UserRole::Admin);
        $this->actingAs($admin)->post(route('admin.customers.store'), $this->payload([], $admin->id));
        $customer = Customer::query()->firstOrFail();

        foreach (['overview', 'contacts', 'products', 'subscriptions', 'invoices', 'payments', 'licenses', 'support', 'activity'] as $tab) {
            $this->actingAs($admin)->get(route('admin.customers.show', ['customer' => $customer, 'tab' => $tab]))
                ->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/Customers/Show')->where('activeTab', $tab));
        }
    }

    public function test_customer_permissions_follow_staff_responsibilities(): void
    {
        $admin = $this->userWithRole(UserRole::Admin);
        $this->actingAs($admin)->post(route('admin.customers.store'), $this->payload([], $admin->id));
        $customer = Customer::query()->firstOrFail();
        $sales = $this->userWithRole(UserRole::Sales);
        $finance = $this->userWithRole(UserRole::Finance);
        $support = $this->userWithRole(UserRole::Support);
        $portalCustomer = $this->userWithRole(UserRole::Customer);

        $this->actingAs($sales)->get(route('admin.customers.index'))->assertOk();
        $this->actingAs($sales)->get(route('admin.customers.edit', $customer))->assertOk();
        $this->actingAs($sales)->delete(route('admin.customers.destroy', $customer))->assertForbidden();
        $this->actingAs($finance)->get(route('admin.customers.show', $customer))->assertOk();
        $this->actingAs($finance)->get(route('admin.customers.edit', $customer))->assertForbidden();
        $this->actingAs($support)->post(route('admin.customers.notes.store', $customer), ['body' => 'Support context.', 'is_private' => true])->assertRedirect();
        $this->actingAs($portalCustomer)->get(route('admin.customers.index'))->assertForbidden();
    }

    public function test_customer_input_is_validated(): void
    {
        $payload = $this->payload();
        $payload['type'] = 'company';
        $payload['status'] = 'unknown';
        $payload['email'] = 'invalid';
        $payload['country'] = 'NGA';

        $this->actingAs($this->userWithRole(UserRole::Admin))->post(route('admin.customers.store'), $payload)
            ->assertSessionHasErrors(['type', 'status', 'email', 'country']);
    }

    private function userWithRole(UserRole $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user;
    }

    /** @param list<int> $productIds
     * @return array<string, mixed>
     */
    private function payload(array $productIds = [], ?int $assignedTo = null): array
    {
        return [
            'type' => 'organization', 'name' => 'ABC Academy', 'email' => 'hello@abcacademy.test', 'phone' => '+2348000000000',
            'website' => 'https://abcacademy.test', 'tax_id' => 'TIN-12345', 'billing_address' => '1 Learning Avenue',
            'city' => 'Lagos', 'state' => 'Lagos', 'country' => 'NG', 'status' => 'lead', 'source' => 'Website',
            'assigned_to' => $assignedTo, 'metadata' => ['segment' => 'education'], 'product_ids' => $productIds,
            'contacts' => [['name' => 'Ada Okafor', 'job_title' => 'Director', 'email' => 'ada@abcacademy.test', 'phone' => '+2348111111111', 'is_primary' => true]],
            'addresses' => [['type' => 'billing', 'label' => 'Head Office', 'address_line_1' => '1 Learning Avenue', 'address_line_2' => null, 'city' => 'Lagos', 'state' => 'Lagos', 'postal_code' => '100001', 'country' => 'NG', 'is_primary' => true]],
        ];
    }
}
