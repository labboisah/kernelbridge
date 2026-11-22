<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get('/profile');

        $response->assertOk();
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_user_can_delete_their_account(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->delete('/profile', [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertNull($user->fresh());
    }

    public function test_correct_password_must_be_provided_to_delete_account(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->delete('/profile', [
                'password' => 'wrong-password',
            ]);

        $response
            ->assertSessionHasErrors('password')
            ->assertRedirect('/profile');

        $this->assertNotNull($user->fresh());
    }

    public function test_administrator_can_upload_replace_and_privately_view_their_photo(): void
    {
        Storage::fake('local');
        $this->seed(RoleAndPermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $other = User::factory()->create();
        $other->assignRole('admin');

        $this->actingAs($admin)->post(route('profile.photo.update'), ['photo' => UploadedFile::fake()->image('photo.png')])
            ->assertSessionHasNoErrors()->assertRedirect(route('profile.edit'));
        $path = $admin->fresh()->admin_photo_path;
        Storage::disk('local')->assertExists($path);
        $url = route('profile.photo', ['v' => substr(hash('sha256', $path), 0, 16)]);
        $this->get(route('profile.edit'))->assertInertia(fn (Assert $page) => $page
            ->where('auth.admin_photo_url', $url)->missing('auth.user.admin_photo_path'));
        $this->get(route('admin.notifications.index'))->assertInertia(fn (Assert $page) => $page
            ->where('auth.admin_photo_url', $url));
        $this->get($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs($other)->get(route('profile.photo'))->assertNotFound();

        $this->actingAs($admin)->post(route('profile.photo.update'), ['photo' => UploadedFile::fake()->image('new.jpg')])->assertSessionHasNoErrors();
        $newPath = $admin->fresh()->admin_photo_path;
        $this->assertNotSame($path, $newPath);
        Storage::disk('local')->assertMissing($path);
        Storage::disk('local')->assertExists($newPath);
        $this->get(route('profile.edit'))->assertInertia(fn (Assert $page) => $page
            ->where('auth.admin_photo_url', route('profile.photo', ['v' => substr(hash('sha256', $newPath), 0, 16)])));
        $this->delete(route('profile.destroy'), ['password' => 'password'])->assertRedirect('/');
        Storage::disk('local')->assertMissing($newPath);
    }

    public function test_photo_upload_is_admin_only_and_rejects_invalid_files(): void
    {
        Storage::fake('local');
        $this->seed(RoleAndPermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        $user = User::factory()->create();
        $user->assignRole('partner');

        $this->actingAs($user)->get(route('profile.photo'))->assertForbidden();
        $this->post(route('profile.photo.update'), ['photo' => UploadedFile::fake()->image('photo.png')])->assertForbidden();
        $this->actingAs($admin)->post(route('profile.photo.update'), ['photo' => UploadedFile::fake()->create('file.pdf', 20, 'application/pdf')])
            ->assertSessionHasErrors('photo');
        $this->post(route('profile.photo.update'), ['photo' => UploadedFile::fake()->image('large.png')->size(2049)])
            ->assertSessionHasErrors('photo');
        $this->assertNull($admin->fresh()->admin_photo_path);
    }

    public function test_company_document_images_are_shared_replaceable_and_removable(): void
    {
        Storage::fake('local');
        $this->seed(RoleAndPermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $other = User::factory()->create();
        $other->assignRole('super_admin');

        $this->actingAs($admin)->post(route('profile.document-assets.update', 'signature'), ['asset' => UploadedFile::fake()->image('signature.png')])
            ->assertSessionHasNoErrors();
        $this->post(route('profile.document-assets.update', 'stamp'), ['asset' => UploadedFile::fake()->image('stamp.jpg')])
            ->assertSessionHasNoErrors();
        $settings = DB::table('document_assets')->where('id', 1)->first();
        Storage::disk('local')->assertExists($settings->signature_path);
        Storage::disk('local')->assertExists($settings->stamp_path);
        $this->actingAs($other)->get(route('profile.edit'))->assertInertia(fn (Assert $page) => $page
            ->where('documentAssets.signature', 'data:image/png;base64,'.base64_encode(Storage::disk('local')->get($settings->signature_path)))
            ->where('documentAssets.stamp', 'data:image/jpeg;base64,'.base64_encode(Storage::disk('local')->get($settings->stamp_path))));
        $this->post(route('profile.document-assets.update', 'stamp'), ['asset' => UploadedFile::fake()->image('new.png')])->assertSessionHasNoErrors();
        Storage::disk('local')->assertMissing($settings->stamp_path);
        $replacement = DB::table('document_assets')->where('id', 1)->value('stamp_path');
        Storage::disk('local')->assertExists($replacement);
        $this->delete(route('profile.document-assets.destroy', 'stamp'))->assertSessionHasNoErrors();
        Storage::disk('local')->assertMissing($replacement);
        $this->assertNull(DB::table('document_assets')->where('id', 1)->value('stamp_path'));
        Storage::disk('local')->assertExists($settings->signature_path);
        $this->assertDatabaseHas('audit_logs', ['event' => 'document_asset.updated', 'actor_id' => $admin->id]);
    }

    public function test_company_document_images_require_admin_and_valid_image_uploads(): void
    {
        Storage::fake('local');
        $this->seed(RoleAndPermissionSeeder::class);
        $partner = User::factory()->create();
        $partner->assignRole('partner');
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($partner)->post(route('profile.document-assets.update', 'stamp'), ['asset' => UploadedFile::fake()->image('stamp.png')])->assertForbidden();
        $this->delete(route('profile.document-assets.destroy', 'signature'))->assertForbidden();
        $this->get(route('profile.edit'))->assertInertia(fn (Assert $page) => $page->where('documentAssets', null));
        $this->actingAs($admin)->post(route('profile.document-assets.update', 'stamp'), ['asset' => UploadedFile::fake()->create('file.pdf', 20, 'application/pdf')])->assertSessionHasErrors('asset');
        $this->post(route('profile.document-assets.update', 'signature'), ['asset' => UploadedFile::fake()->image('large.png')->size(2049)])->assertSessionHasErrors('asset');
        $this->post('/profile/document-assets/unknown', ['asset' => UploadedFile::fake()->image('stamp.png')])->assertNotFound();
        $this->assertDatabaseHas('document_assets', ['id' => 1, 'stamp_path' => null, 'signature_path' => null]);
    }
}
