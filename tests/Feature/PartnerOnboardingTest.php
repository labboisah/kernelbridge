<?php

namespace Tests\Feature;

use App\Models\PartnerApplication;
use App\Models\PartnerBankDetails;
use App\Models\ProspectiveCustomer;
use App\Models\User;
use App\Notifications\PartnerApplicationApproved;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
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

    private function applicant(): User
    {
        $user = User::factory()->create();
        $user->assignRole('customer');

        return $user;
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
            $user = $this->applicant();
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
        $user = $this->applicant();
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

    public function test_admin_lists_and_filters_applications_across_all_statuses(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        foreach (['draft', 'submitted', 'approved', 'declined'] as $status) {
            $user = User::factory()->create(['name' => ucfirst($status).' Applicant', 'email' => $status.'@example.test']);
            (new PartnerApplication)->forceFill(['user_id' => $user->id, 'type' => 'developer', 'status' => $status])->save();
        }
        $this->actingAs($admin)->get(route('admin.partners.index'))->assertInertia(fn (Assert $page) => $page
            ->has('applications.data', 4)
            ->where('applications.total', 4));
        $this->get(route('admin.partners.index', ['status' => 'draft', 'search' => 'Draft']))->assertInertia(fn (Assert $page) => $page
            ->has('applications.data', 1)
            ->where('applications.data.0.status', 'draft')
            ->where('filters.status', 'draft')
            ->where('filters.search', 'Draft'));
        $this->get(route('admin.partners.index', ['search' => '0']))->assertInertia(fn (Assert $page) => $page
            ->has('applications.data', 0));
        $this->get(route('admin.partners.index', ['status' => 'unknown']))->assertSessionHasErrors('status');
    }

    public function test_admin_can_edit_application_details_without_changing_review_or_consent(): void
    {
        $applicant = $this->applicant();
        $this->actingAs($applicant)->post('/partners/developer/onboarding', $this->submission())->assertRedirect();
        $application = PartnerApplication::firstOrFail();
        $consentedAt = $application->consented_at;
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $url = route('admin.partners.details.update', $application);
        $this->patch($url, ['organisation' => 'Unauthorized'])->assertForbidden();
        $this->actingAs($admin)->patch($url, [
            'organisation' => 'Updated organization',
            'phone' => '08012345678',
            'guided_answers' => ['experience_level' => 'Advanced', 'business_skills' => ['Sales']],
            'status' => 'approved', 'consented_at' => null, 'user_id' => $admin->id,
        ])->assertSessionHasNoErrors();
        $application->refresh();
        $this->assertSame('Updated organization', $application->organisation);
        $this->assertSame('submitted', $application->status);
        $this->assertSame($applicant->id, $application->user_id);
        $this->assertTrue($consentedAt->equalTo($application->consented_at));
        $this->assertSame('Advanced', $application->guided_answers['experience_level']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'partner.application_details_updated', 'actor_id' => $admin->id]);
        $this->patch($url, ['phone' => '', 'guided_answers' => ['experience_level' => 'Invalid']])
            ->assertSessionHasErrors('guided_answers.experience_level');
        $this->patch($url, ['phone' => ''])->assertSessionHasErrors('phone');
    }

    public function test_admin_deletes_application_but_keeps_user_and_removes_photo(): void
    {
        Storage::fake('local');
        $applicant = User::factory()->create();
        $path = UploadedFile::fake()->image('photo.png')->store('partner-photos', 'local');
        $application = new PartnerApplication;
        $application->forceFill(['user_id' => $applicant->id, 'type' => 'developer', 'status' => 'draft', 'photo_path' => $path])->save();
        $url = route('admin.partners.destroy', $application);
        $this->actingAs($applicant)->delete($url)->assertForbidden();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin)->delete($url)->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('partner_applications', ['id' => $application->id]);
        $this->assertDatabaseHas('users', ['id' => $applicant->id]);
        Storage::disk('local')->assertMissing($path);
        $this->assertDatabaseHas('audit_logs', ['event' => 'partner.application_deleted', 'actor_id' => $admin->id]);
    }

    public function test_admin_is_warned_when_deleted_application_photo_cannot_be_removed(): void
    {
        $applicant = User::factory()->create();
        $application = new PartnerApplication;
        $application->forceFill(['user_id' => $applicant->id, 'type' => 'developer', 'status' => 'draft', 'photo_path' => 'partner-photos/test.png'])->save();
        $disk = \Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('delete')->once()->with('partner-photos/test.png')->andReturn(false);
        Storage::shouldReceive('disk')->once()->with('local')->andReturn($disk);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin)->delete(route('admin.partners.destroy', $application))
            ->assertSessionHas('error', 'The application was deleted, but its private photo could not be removed. Contact an administrator.');
        $this->assertDatabaseMissing('partner_applications', ['id' => $application->id]);
    }

    public function test_sales_agent_application_with_commercial_activity_cannot_be_deleted(): void
    {
        $applicant = User::factory()->create();
        $applicant->assignRole('agent');
        $application = new PartnerApplication;
        $application->forceFill(['user_id' => $applicant->id, 'type' => 'sales_agent', 'status' => 'approved'])->save();
        (new ProspectiveCustomer(['name' => 'Prospect']))->forceFill(['partner_user_id' => $applicant->id])->save();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin)->delete(route('admin.partners.destroy', $application))->assertSessionHasErrors('application_delete');
        $this->assertDatabaseHas('partner_applications', ['id' => $application->id]);
        $this->assertTrue($applicant->isApprovedAgent());
        $this->assertDatabaseMissing('audit_logs', ['event' => 'partner.application_deleted']);
    }

    public function test_submission_requires_contact_details_and_consent(): void
    {
        $this->actingAs($this->applicant())->post('/partners/investor/onboarding', ['action' => 'submit'])->assertSessionHasErrors(['phone', 'location', 'details', 'consent']);
        $this->assertDatabaseCount('partner_applications', 0);
    }

    public function test_authentication_and_application_isolation(): void
    {
        $this->get('/partners/developer/onboarding')->assertRedirect('/login');
        $first = $this->applicant();
        $second = $this->applicant();
        $this->actingAs($first)->post('/partners/developer/onboarding', $this->submission());
        $this->actingAs($second)->get('/partners/developer/onboarding')->assertInertia(fn (Assert $p) => $p->where('application', null));
        $this->get('/partners/administrator/onboarding')->assertNotFound();
        $this->get('/partners/administrator/register')->assertNotFound();
    }

    public function test_guided_responses_are_visible_to_applicant_and_admin(): void
    {
        $user = $this->applicant();
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
        $this->actingAs($this->applicant());
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
        $user = $this->applicant();
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
        $user = $this->applicant();
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
        $user = $this->applicant();
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
        $user = $this->applicant();
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
        $user = $this->applicant();
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
