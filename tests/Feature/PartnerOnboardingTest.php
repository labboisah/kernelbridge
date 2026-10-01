<?php

namespace Tests\Feature;

use App\Models\PartnerApplication;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PartnerOnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RoleAndPermissionSeeder::class);
    }

    private function submission(): array
    {
        return ['action' => 'submit', 'organisation' => 'Example', 'phone' => '+2348000000000', 'location' => 'Lagos, Nigeria', 'details' => 'Interested in working together.', 'consent' => true];
    }

    public function test_public_choices_and_registration_continue_to_selected_onboarding(): void
    {
        $this->get('/partners')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Public/Partners')->has('types', 3));
        $this->get('/partners/developer/register')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Partner/Register')->where('category.label', 'Developer'));
        $this->post('/partners/developer/register', ['name' => 'New Partner', 'email' => 'partner@example.test', 'account_type' => 'individual', 'password' => 'password', 'password_confirmation' => 'password'])->assertRedirect(route('partners.onboarding', 'developer'));
        $this->assertAuthenticated();
        $this->assertTrue(auth()->user()->hasRole('partner'));
        $this->assertFalse(auth()->user()->customers()->exists());
        $this->assertFalse(auth()->user()->hasRole('sales'));
    }

    public function test_each_type_can_save_resume_and_submit(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        foreach (array_keys(config('partners')) as $type) {
            $this->travel(61)->seconds();
            $url = route('partners.onboarding', $type);
            $this->post($url, ['action' => 'save', 'details' => 'Draft'])->assertRedirect($url);
            $this->get($url)->assertOk()->assertInertia(fn (Assert $p) => $p->where('application.details', 'Draft')->where('application.status', 'draft'));
            $this->post($url, $this->submission() + ['user_id' => 999, 'status' => 'approved'])->assertRedirect($url);
            $this->assertDatabaseHas('partner_applications', ['user_id' => $user->id, 'type' => $type, 'status' => 'submitted']);
            $this->post($url, $this->submission())->assertStatus(409);
        }
        $this->assertDatabaseCount('partner_applications', 3);
    }

    public function test_submission_requires_contact_details_and_consent(): void
    {
        $this->actingAs(User::factory()->create())->post('/partners/investor/onboarding', ['action' => 'submit'])->assertSessionHasErrors(['phone', 'location', 'details', 'consent']);
        $this->assertDatabaseCount('partner_applications', 0);
    }

    public function test_authentication_and_application_isolation(): void
    {
        $this->get('/partners/developer/onboarding')->assertRedirect('/login');
        $first = User::factory()->create();
        $second = User::factory()->create();
        $this->actingAs($first)->post('/partners/developer/onboarding', $this->submission());
        $this->actingAs($second)->get('/partners/developer/onboarding')->assertInertia(fn (Assert $p) => $p->where('application', null));
        $this->get('/partners/administrator/onboarding')->assertNotFound();
        $this->get('/partners/administrator/register')->assertNotFound();
    }

    public function test_only_admin_can_review_and_decision_is_visible_to_owner(): void
    {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $this->actingAs($user)->post('/partners/sales_agent/onboarding', $this->submission());
        $application = PartnerApplication::firstOrFail();
        $review = ['status' => 'approved', 'review_note' => 'Contact our team to arrange an introduction.'];
        $this->get('/admin/partners')->assertForbidden();
        $this->put('/admin/partners/'.$application->id, $review)->assertForbidden();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin)->get('/admin/partners')->assertOk();
        $this->put('/admin/partners/'.$application->id, $review)->assertRedirect();
        $this->assertDatabaseHas('partner_applications', ['id' => $application->id, 'status' => 'approved', 'reviewed_by' => $admin->id]);
        $this->actingAs($user)->get('/partners/sales_agent/onboarding')->assertInertia(fn (Assert $p) => $p->where('application.review_note', $review['review_note']));
        $this->assertFalse($user->fresh()->hasRole('sales'));
    }
}
