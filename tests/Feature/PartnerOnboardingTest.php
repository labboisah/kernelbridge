<?php

namespace Tests\Feature;

use App\Models\PartnerApplication;
use App\Models\PartnerBankDetails;
use App\Models\User;
use App\Notifications\PartnerApplicationApproved;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Notifications\SendQueuedNotifications;
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

    public function test_each_type_can_submit_and_view_application(): void
    {
        foreach (array_keys(config('partners')) as $type) {
            $user = User::factory()->create();
            $url = route('partners.onboarding', $type);
            $this->actingAs($user)->post($url, $this->submission() + ['user_id' => 999, 'status' => 'approved'])->assertRedirect($url);
            $this->assertDatabaseHas('partner_applications', ['user_id' => $user->id, 'type' => $type, 'status' => 'submitted']);
            $this->get($url)->assertOk()->assertInertia(fn (Assert $p) => $p
                ->where('application.status', 'submitted')
                ->where('readOnly', true));
            $this->post($url, $this->submission())->assertForbidden();
        }
        $this->assertDatabaseCount('partner_applications', 3);
    }

    public function test_partner_can_only_view_saved_draft_and_cannot_start_another_application(): void
    {
        $user = User::factory()->create();
        $url = route('partners.onboarding', 'developer');
        $this->actingAs($user)->post($url, ['action' => 'save', 'details' => 'Draft'])->assertRedirect($url);

        $this->get($url)->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('application.details', 'Draft')
            ->where('application.status', 'draft')
            ->where('readOnly', true));
        $this->post($url, ['action' => 'submit'] + $this->submission())->assertForbidden();
        $this->get(route('partners.onboarding', 'sales_agent'))->assertNotFound();
        $this->post(route('partners.onboarding', 'sales_agent'), $this->submission())->assertForbidden();
        $this->assertDatabaseCount('partner_applications', 1);
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

    public function test_guided_responses_are_visible_to_applicant_and_admin(): void
    {
        $user = User::factory()->create();
        $answers = [
            'background_areas' => ['Healthcare', 'Software and technology'],
            'digital_skills' => ['Email and internet', 'Technical support'],
            'experience_level' => 'Intermediate',
            'installation_experience' => 'Yes',
            'installation_skills' => ['Install applications'],
            'networking_experience' => 'No',
            'networking_skills' => [],
            'business_skills' => ['Sales'],
            'management_experience' => null,
            'management_skills' => [],
        ];
        $this->actingAs($user)->post('/partners/developer/onboarding', $this->submission() + [
            'background_knowledge' => 'Existing background',
            'guided_answers' => json_encode($answers, JSON_THROW_ON_ERROR),
        ])->assertRedirect();
        $application = PartnerApplication::firstOrFail();
        $this->assertSame($answers, $application->guided_answers);
        $this->assertSame('Existing background', $application->background_knowledge);
        $this->get('/partners/developer/onboarding')->assertInertia(fn (Assert $p) => $p
            ->where('readOnly', true)
            ->where('application.guided_answers.installation_experience', 'Yes')
            ->where('application.guided_answers.networking_experience', 'No')
            ->where('application.guided_answers.digital_skills', ['Email and internet', 'Technical support'])
            ->where('questionnaire.0.questions.2.type', 'single'));
        $this->post('/partners/developer/onboarding', ['action' => 'save'])->assertForbidden();

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin)->get('/admin/partners')->assertInertia(fn (Assert $p) => $p
            ->where('applications.data.0.guided_answers.installation_experience', 'Yes')
            ->where('applications.data.0.background_knowledge', 'Existing background')
            ->has('questionnaire', 3));
    }

    public function test_guided_answers_reject_invalid_values_and_unknown_questions(): void
    {
        $this->actingAs(User::factory()->create());
        $url = '/partners/developer/onboarding';
        $this->post($url, ['action' => 'save', 'guided_answers' => '{'])->assertSessionHasErrors('guided_answers');
        $this->post($url, ['action' => 'save', 'guided_answers' => json_encode(['experience_level' => 'Expert plus', 'installation_experience' => 'Maybe', 'digital_skills' => ['Invalid']], JSON_THROW_ON_ERROR)])
            ->assertSessionHasErrors(['answers.experience_level', 'answers.installation_experience', 'answers.digital_skills.0']);
        $this->post($url, ['action' => 'save', 'guided_answers' => json_encode(['unknown' => 'Yes'], JSON_THROW_ON_ERROR)])->assertSessionHasErrors('answers');
        $this->post($url, ['action' => 'save', 'guided_answers' => json_encode(['digital_skills' => ['Technical support', 'Technical support']], JSON_THROW_ON_ERROR)])->assertSessionHasErrors('answers.digital_skills.0');
        $this->assertDatabaseCount('partner_applications', 0);
    }

    public function test_only_admin_can_review_and_decision_is_visible_to_owner(): void
    {
        Notification::fake();
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
        Notification::assertSentTo($user, PartnerApplicationApproved::class, function ($notification) use ($user) {
            return $notification->type === 'sales_agent'
                && $notification->toMail($user)->actionUrl === route('partner.bank.edit');
        });
        $this->put('/admin/partners/'.$application->id, $review)->assertStatus(409);
        Notification::assertSentToTimes($user, PartnerApplicationApproved::class, 1);
        $this->actingAs($user)->get('/partners/sales_agent/onboarding')->assertInertia(fn (Assert $p) => $p->where('application.review_note', $review['review_note']));
        $this->assertFalse($user->fresh()->hasRole('sales'));
    }

    public function test_rejected_application_does_not_send_approval_or_allow_bank_details(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $this->actingAs($user)->post('/partners/developer/onboarding', $this->submission());
        $application = PartnerApplication::firstOrFail();
        $this->get(route('partner.bank.edit'))->assertForbidden();
        $this->put(route('partner.bank.update'), ['bank_name' => 'Bank', 'account_name' => 'Name', 'account_number' => '123'])->assertForbidden();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin)->put('/admin/partners/'.$application->id, ['status' => 'declined', 'review_note' => 'Not approved'])->assertRedirect();
        Notification::assertNotSentTo($user, PartnerApplicationApproved::class);
        $this->actingAs($user)->get(route('partner.bank.edit'))->assertForbidden();
    }

    public function test_approval_queues_an_email_for_the_applicant(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $this->actingAs($user)->post('/partners/investor/onboarding', $this->submission());
        $application = PartnerApplication::firstOrFail();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin)->put('/admin/partners/'.$application->id, ['status' => 'approved', 'review_note' => 'Welcome'])->assertRedirect();
        Queue::assertPushed(SendQueuedNotifications::class, function ($job) use ($user) {
            return $job->notification instanceof PartnerApplicationApproved
                && $job->notification->afterCommit === true
                && $job->notifiables->contains('id', $user->id);
        });
    }

    public function test_approved_partner_bank_details_are_encrypted_and_restricted_to_owner_and_admin(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $this->actingAs($user)->post('/partners/developer/onboarding', $this->submission());
        $application = PartnerApplication::firstOrFail();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin)->put('/admin/partners/'.$application->id, ['status' => 'approved', 'review_note' => 'Welcome'])->assertRedirect();

        $details = ['bank_name' => 'Example Bank', 'account_name' => 'Partner Name', 'account_number' => '0012345678'];
        $this->actingAs($user)->put(route('partner.bank.update'), $details)->assertRedirect(route('partner.bank.edit'));
        $this->put(route('partner.bank.update'), ['bank_name' => '', 'account_name' => $details['account_name'], 'account_number' => $details['account_number']])
            ->assertSessionHasErrors('bank_name')->assertSessionMissing('_old_input.account_number');
        $raw = DB::table('partner_bank_details')->where('user_id', $user->id)->first();
        foreach ($details as $field => $value) {
            $this->assertNotSame($value, $raw->$field);
        }
        $this->assertSame($details['account_number'], PartnerBankDetails::where('user_id', $user->id)->firstOrFail()->account_number);
        $this->get(route('partner.bank.edit'))->assertHeader('Cache-Control', 'no-store, private')
            ->assertInertia(fn (Assert $p) => $p->where('bankDetails.account_number', $details['account_number']));
        $this->actingAs(User::factory()->create())->get(route('partner.bank.edit'))->assertForbidden();
        $this->get('/admin/partners')->assertForbidden();
        $this->actingAs($admin)->get('/admin/partners')->assertHeader('Cache-Control', 'no-store, private')
            ->assertInertia(fn (Assert $p) => $p->where('applications.data.0.bank_details.account_number', $details['account_number']));
    }

    public function test_experience_and_private_photo_are_saved_and_only_owner_or_admin_can_view_photo(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $photo = UploadedFile::fake()->createWithContent(
            'photo.png',
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/lE8AAAAASUVORK5CYII='),
        );
        $this->actingAs($user)->post('/partners/developer/onboarding', [
            'action' => 'save', 'background_knowledge' => 'Healthcare software', 'digital_skills' => 'Word processing',
            'installation_experience' => 'Workstation setup', 'networking_experience' => 'LAN', 'business_skills' => 'Sales',
            'management_skills' => 'Team leadership', 'photo' => $photo,
        ])->assertRedirect();
        $application = PartnerApplication::firstOrFail();
        Storage::disk('local')->assertExists($application->photo_path);
        $this->get('/partners/developer/onboarding')->assertInertia(fn (Assert $p) => $p
            ->where('readOnly', true)
            ->where('application.background_knowledge', 'Healthcare software')
            ->where('application.management_skills', 'Team leadership')
            ->where('application.has_photo', true)
            ->missing('application.photo_path'));
        $this->get(route('partners.photo', $application))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->get('/partner')->assertInertia(fn (Assert $p) => $p
            ->where('auth.partner_photo_url', route('partners.photo', $application)));
        $this->actingAs(User::factory()->create())->get(route('partners.photo', $application))->assertForbidden();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin)->get(route('partners.photo', $application))->assertOk();
        $this->actingAs($user)->post('/partners/developer/onboarding', ['action' => 'save', 'photo' => UploadedFile::fake()->create('bad.pdf', 20, 'application/pdf')])->assertForbidden();
        Storage::disk('local')->assertExists($application->fresh()->photo_path);
    }
}
