<?php

namespace Tests\Feature;

use App\Domain\Products\Exceptions\ProductArchiveBuildException;
use App\Domain\Products\Services\GitHubProductArchiveBuilder;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class ProductReleaseArchiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_repository_release_is_stored_privately_with_checksum(): void
    {
        Storage::fake('local');
        $product = Product::factory()->create([
            'repository_url' => 'https://github.com/acme/bridge-crm.git',
            'repository_visibility' => 'public',
            'stable_release_version' => '2.3.0',
            'stable_release_tag' => 'v2.3.0',
        ]);
        $release = $product->releases()->create(['version' => '2.3.0', 'tag' => 'v2.3.0', 'status' => 'building']);
        $archiveContent = $this->makeArchive();
        $archiveUrl = 'https://codeload.github.com/acme/bridge-crm/legacy.zip/refs/tags/v2.3.0';

        Http::fake([
            'api.github.com/repos/acme/bridge-crm/zipball/v2.3.0' => Http::response('', 302, ['Location' => $archiveUrl]),
            'codeload.github.com/*' => Http::response($archiveContent),
        ]);

        $result = app(GitHubProductArchiveBuilder::class)->build($release);

        Storage::disk('local')->assertExists($result['storage_path']);
        $this->assertSame(hash('sha256', $archiveContent), $result['sha256']);
        $this->assertSame(strlen($archiveContent), $result['archive_size']);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'api.github.com/repos/acme/bridge-crm')
            && ! $request->hasHeader('Authorization'));
    }

    public function test_private_repository_uses_installation_token_and_never_sends_it_to_archive_host(): void
    {
        Storage::fake('local');
        $keyPair = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        $this->assertNotFalse($keyPair);
        openssl_pkey_export($keyPair, $privateKey);
        $keyPath = tempnam(sys_get_temp_dir(), 'kb-github-key-');
        file_put_contents($keyPath, $privateKey);
        config(['services.github_app.application_id' => '98765', 'services.github_app.private_key_path' => $keyPath]);

        $product = Product::factory()->create([
            'repository_url' => 'https://github.com/acme/private-crm',
            'repository_visibility' => 'private',
            'github_installation_id' => '12345678',
        ]);
        $release = $product->releases()->create(['version' => '1.0.0', 'tag' => 'v1.0.0', 'status' => 'building']);
        Http::fake([
            'api.github.com/app/installations/12345678/access_tokens' => Http::response(['token' => 'installation-token'], 201),
            'api.github.com/repos/acme/private-crm/zipball/v1.0.0' => Http::response('', 302, ['Location' => 'https://codeload.github.com/acme/private-crm/legacy.zip/refs/tags/v1.0.0']),
            'codeload.github.com/*' => Http::response($this->makeArchive()),
        ]);

        try {
            $result = app(GitHubProductArchiveBuilder::class)->build($release);
            Storage::disk('local')->assertExists($result['storage_path']);
            Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/zipball/v1.0.0')
                && $request->hasHeader('Authorization', 'Bearer installation-token'));
            Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'codeload.github.com')
                && ! $request->hasHeader('Authorization'));
        } finally {
            unlink($keyPath);
        }
    }

    public function test_archive_redirect_must_remain_on_github_codeload_host(): void
    {
        $product = Product::factory()->create([
            'repository_url' => 'https://github.com/acme/bridge-crm',
            'repository_visibility' => 'public',
        ]);
        $release = $product->releases()->create(['version' => '1.0.0', 'tag' => 'v1.0.0', 'status' => 'building']);
        Http::fake([
            'api.github.com/repos/acme/bridge-crm/zipball/v1.0.0' => Http::response('', 302, ['Location' => 'https://attacker.example/archive.zip']),
        ]);

        try {
            app(GitHubProductArchiveBuilder::class)->build($release);
            $this->fail('Unexpected archive host should be rejected.');
        } catch (ProductArchiveBuildException $exception) {
            $this->assertSame('repository_archive_unavailable', $exception->failureCode);
        }
    }

    private function makeArchive(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'kb-test-archive-');
        $archive = new \ZipArchive();
        $this->assertNotFalse($path);
        $this->assertSame(true, $archive->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        $this->assertTrue($archive->addFromString('README.txt', 'Installation archive test fixture.'));
        $this->assertTrue($archive->close());
        $contents = file_get_contents($path);
        unlink($path);
        $this->assertNotFalse($contents);

        return $contents;
    }
}
