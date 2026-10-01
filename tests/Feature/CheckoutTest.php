<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Domain\Finance\Services\InvoiceManager;
use App\Domain\Licensing\Services\PackageChangeService;
use App\Domain\Licensing\Services\PaidLicenseService;
use App\Models\{User,Customer,Plan,Invoice,Subscription,ClientSetupBundle,HostingSetting,Payment};
use Database\Seeders\{ProductSeeder,MediFlowPackageSeeder,RoleAndPermissionSeeder};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Http,Notification,DB};
use Inertia\Testing\AssertableInertia as Assert;
class CheckoutTest extends TestCase {
 use RefreshDatabase;
 private User $owner;
 private Plan $plan;
 protected function setUp(): void {
  parent::setUp(); Notification::fake();
  $this->seed([RoleAndPermissionSeeder::class,ProductSeeder::class,MediFlowPackageSeeder::class]);
  $this->owner=User::factory()->create();
  $customer=Customer::factory()->create();$customer->users()->attach($this->owner,['is_primary'=>true]);
  $this->plan=Plan::where('slug','pharmacy')->firstOrFail();$this->plan->update(['price'=>'10000.00','setup_fee'=>'1000.00','billing_cycle'=>'annual']);
  $this->actingAs($this->owner);
 }
 private function order(string $mode='online'): Invoice {
  $this->post(route('portal.checkout.store'),['packages'=>[$this->plan->uuid],'implementation'=>['mode'=>$mode,'domain'=>'clinic.example','monthly_price'=>'0.01'],'total'=>'0.01'])->assertSessionHasNoErrors()->assertRedirect();
  return Invoice::query()->sole();
 }
 private function pay(Invoice $invoice): void {
  app(InvoiceManager::class)->payment($invoice,['amount'=>$invoice->balance,'method'=>'bank_transfer','reference'=>'test-'.str()->uuid(),'paid_at'=>now()],$this->owner);
 }
 public function test_checkout_displays_account_plan_and_admin_hosting_price(): void {
  $this->get(route('portal.checkout.show',['packages'=>[$this->plan->uuid]]))->assertOk()->assertInertia(fn(Assert $p)=>$p->component('Customer/Checkout')->where('plans.0.uuid',$this->plan->uuid)->where('hosting.monthly_price','20000.00'));
 }
 public function test_server_prices_hosting_for_the_term_and_rejects_duplicate_orders(): void {
  $invoice=$this->order();$this->assertSame('251000.00',$invoice->total);$this->assertDatabaseCount('client_setup_bundles',0);
  $this->assertSame(12,(int)$invoice->items()->where('description','like','Online%')->sole()->quantity);
  $this->post(route('portal.checkout.store'),['packages'=>[$this->plan->uuid],'implementation'=>['mode'=>'local']])->assertSessionHasErrors('packages');
  $this->assertDatabaseCount('invoices',1);
 }
 public function test_local_implementation_has_no_hosting_charge_and_disabled_online_cannot_be_bought(): void {
  HostingSetting::find(1)->update(['enabled'=>false]);
  $this->post(route('portal.checkout.store'),['packages'=>[$this->plan->uuid],'implementation'=>['mode'=>'online']])->assertSessionHasErrors('implementation.mode');
  $this->assertSame('11000.00',$this->order('local')->total);
 }
 public function test_paid_setup_is_encrypted_private_and_api_token_is_subscription_restricted(): void {
  $invoice=$this->order();$subscription=Subscription::sole();
  $this->get(route('portal.services.environment',$subscription))->assertNotFound();
  $this->pay($invoice);
  $bundle=ClientSetupBundle::sole();$token=$bundle->secrets['api_token'];
  $this->assertStringNotContainsString($token,DB::table('client_setup_bundles')->value('secrets'));
  $this->get(route('portal.services.environment',$subscription))->assertOk()->assertSee('APP_MODE=client')->assertSee('KERNELBRIDGE_LICENSE_KEY=KBT-');
  $this->get(route('portal.invoices.show',$invoice))->assertInertia(fn(Assert $p)=>$p->has('clientSetup.license_key')->has('clientSetup.download_url'));
  app(PaidLicenseService::class)->complete($invoice->fresh(),$this->owner);$this->assertDatabaseCount('client_setup_bundles',1);$this->assertDatabaseCount('api_client_credentials',1);
  $this->withToken($token)->getJson('/api/v1/subscriptions/status?subscription_uuid='.$subscription->uuid)->assertOk();
  $other=Subscription::factory()->create(['product_id'=>$subscription->product_id]);
  $this->withToken($token)->getJson('/api/v1/subscriptions/status?subscription_uuid='.$other->uuid)->assertNotFound();
  $this->withToken($token)->getJson('/api/v1/mcp/customers/search')->assertForbidden();
  $this->actingAs(User::factory()->create())->get(route('portal.services.environment',$subscription))->assertForbidden();
 }
 public function test_admin_controls_hosting_price_and_customer_cannot_edit_it(): void {
  $this->put(route('admin.hosting.update'),['enabled'=>true,'currency'=>'NGN','monthly_price'=>'25000.00'])->assertForbidden();
  $admin=User::factory()->create();$admin->assignRole('admin');
  $this->actingAs($admin)->put(route('admin.hosting.update'),['enabled'=>true,'currency'=>'NGN','monthly_price'=>'25000.00'])->assertSessionHasNoErrors();
  $this->actingAs($this->owner);$this->assertSame('311000.00',$this->order()->total);
 }
 public function test_hosting_renewal_retains_contracted_price_after_admin_changes(): void {
  $invoice=$this->order();$this->pay($invoice);$subscription=Subscription::sole();
  HostingSetting::find(1)->update(['monthly_price'=>'25000.00']);
  $this->travelTo($subscription->ends_at->copy()->addSecond());
  $renewal=app(PackageChangeService::class)->change($subscription,[$this->plan->uuid],$this->owner,false,true);
  $this->assertSame('250000.00',$renewal->total);
 }
 public function test_flutterwave_verified_payment_generates_code_and_client_settings_once(): void {
  config(['services.flutterwave.secret_key'=>'test','services.flutterwave.base_url'=>'https://api.flutterwave.com/v3']);
  $invoice=$this->order();
  Http::fake(['https://api.flutterwave.com/v3/payments'=>Http::response(['status'=>'success','data'=>['link'=>'https://checkout.flutterwave.test/pay']])]);
  $this->post(route('portal.invoices.pay-online',$invoice))->assertRedirect('https://checkout.flutterwave.test/pay');
  Http::assertSent(fn($r)=>$r['amount']==='251000.00');
  $reference=Payment::sole()->transaction_reference;
  Http::fake(['https://api.flutterwave.com/v3/transactions/123/verify'=>Http::response(['status'=>'success','data'=>['id'=>123,'status'=>'successful','amount'=>'251000.00','currency'=>'NGN','tx_ref'=>$reference,'payment_type'=>'card']])]);
  $url=route('payments.flutterwave.callback',['status'=>'successful','tx_ref'=>$reference,'transaction_id'=>'123']);
  $this->get($url)->assertRedirect(route('portal.invoices.show',$invoice));$this->get($url)->assertRedirect(route('portal.invoices.show',$invoice));
  $this->assertDatabaseCount('software_licenses',1);$this->assertDatabaseCount('client_setup_bundles',1);
 }
}
