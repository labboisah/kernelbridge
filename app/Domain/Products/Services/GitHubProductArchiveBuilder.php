<?php

namespace App\Domain\Products\Services;

use App\Domain\Products\Exceptions\ProductArchiveBuildException;
use App\Models\ProductRelease;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

final class GitHubProductArchiveBuilder
{
    private const MAX_ARCHIVE_BYTES = 536870912;

    /** @return array{storage_path: string, sha256: string, archive_size: int} */
    public function build(ProductRelease $release): array
    {
        $product = $release->product;
        [$owner, $repository] = $this->repositoryParts((string) $product->repository_url);
        $headers = [
            'Accept' => 'application/vnd.github+json',
            'User-Agent' => 'KernelBridge-Product-Releases',
            'X-GitHub-Api-Version' => '2022-11-28',
        ];

        if ($product->repository_visibility === 'private') {
            $token = $this->installationToken((string) $product->github_installation_id, $headers);
            $headers['Authorization'] = 'Bearer '.$token;
        }

        $url = 'https://api.github.com/repos/'.$owner.'/'.$repository.'/zipball/'.implode('/', array_map('rawurlencode', explode('/', $release->tag)));
        $archiveResponse = Http::withHeaders($headers)->connectTimeout(10)->timeout(30)->withOptions(['allow_redirects' => false])->get($url);
        if ($archiveResponse->status() !== 302 || ! $this->isGitHubArchiveUrl((string) $archiveResponse->header('Location'))) {
            throw new ProductArchiveBuildException('repository_archive_unavailable');
        }

        $download = Http::withHeaders(['User-Agent' => 'KernelBridge-Product-Releases'])
            ->connectTimeout(10)
            ->timeout(240)
            ->withOptions(['allow_redirects' => false, 'stream' => true])
            ->get((string) $archiveResponse->header('Location'));
        if ($download->status() !== 200) {
            throw new ProductArchiveBuildException('archive_download_failed');
        }

        return $this->storeArchive($release, $download);
    }

    /** @return array{string, string} */
    private function repositoryParts(string $url): array
    {
        $parts = parse_url($url);
        $path = trim((string) ($parts['path'] ?? ''), '/');
        $segments = explode('/', $path);
        if (($parts['scheme'] ?? null) !== 'https'
            || strtolower((string) ($parts['host'] ?? '')) !== 'github.com'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || count($segments) !== 2) {
            throw new ProductArchiveBuildException('invalid_repository_configuration');
        }

        $repository = preg_replace('/\.git$/i', '', $segments[1]) ?? '';
        if (! preg_match('/^[A-Za-z0-9_.-]+$/', $segments[0]) || ! preg_match('/^[A-Za-z0-9_.-]+$/', $repository)) {
            throw new ProductArchiveBuildException('invalid_repository_configuration');
        }

        return [$segments[0], $repository];
    }

    private function isGitHubArchiveUrl(string $url): bool
    {
        $parts = parse_url($url);

        return ($parts['scheme'] ?? null) === 'https'
            && strtolower((string) ($parts['host'] ?? '')) === 'codeload.github.com'
            && ! isset($parts['user']) && ! isset($parts['pass']);
    }

    /** @param array<string, string> $headers */
    private function installationToken(string $installationId, array $headers): string
    {
        $appId = (string) config('services.github_app.application_id');
        $privateKeyPath = (string) config('services.github_app.private_key_path');
        $privateKey = $privateKeyPath !== '' ? @file_get_contents($privateKeyPath) : false;
        if ($appId === '' || $privateKey === false) {
            throw new ProductArchiveBuildException('github_app_not_configured');
        }

        $issuedAt = now()->timestamp - 60;
        $header = $this->base64Url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $payload = $this->base64Url((string) json_encode(['iat' => $issuedAt, 'exp' => $issuedAt + 540, 'iss' => $appId]));
        $unsigned = $header.'.'.$payload;
        $key = openssl_pkey_get_private($privateKey);
        if ($key === false || ! openssl_sign($unsigned, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new ProductArchiveBuildException('github_app_authentication_failed');
        }
        $jwt = $unsigned.'.'.$this->base64Url($signature);
        $response = Http::withToken($jwt)->withHeaders($headers)
            ->connectTimeout(10)->timeout(20)
            ->post('https://api.github.com/app/installations/'.rawurlencode($installationId).'/access_tokens');

        $token = $response->json('token');
        if (! $response->successful() || ! is_string($token) || $token === '') {
            throw new ProductArchiveBuildException('github_app_installation_unavailable');
        }

        return $token;
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /** @return array{storage_path: string, sha256: string, archive_size: int} */
    private function storeArchive(ProductRelease $release, Response $response): array
    {
        $temporaryPath = tempnam(sys_get_temp_dir(), 'kb-release-');
        if ($temporaryPath === false) {
            throw new ProductArchiveBuildException('temporary_storage_unavailable');
        }

        try {
            $output = fopen($temporaryPath, 'wb');
            if ($output === false) {
                throw new ProductArchiveBuildException('temporary_storage_unavailable');
            }

            try {
                $body = $response->toPsrResponse()->getBody();
                $size = 0;
                while (! $body->eof()) {
                    $chunk = $body->read(8192);
                    $size += strlen($chunk);
                    if ($size > self::MAX_ARCHIVE_BYTES) {
                        throw new ProductArchiveBuildException('archive_exceeds_size_limit');
                    }
                    if (fwrite($output, $chunk) !== strlen($chunk)) {
                        throw new ProductArchiveBuildException('temporary_storage_write_failed');
                    }
                }
            } finally {
                fclose($output);
            }

            $archive = new \ZipArchive();
            if ($archive->open($temporaryPath, \ZipArchive::CHECKCONS) !== true) {
                throw new ProductArchiveBuildException('invalid_archive');
            }
            $archive->close();

            $storagePath = sprintf('product-releases/%s/%s/%d.zip', $release->product->uuid, $release->version, $release->id);
            $input = fopen($temporaryPath, 'rb');
            $stored = $input !== false && Storage::disk('local')->put($storagePath, $input);
            if (is_resource($input)) {
                fclose($input);
            }

            if (! $stored) {
                throw new ProductArchiveBuildException('private_archive_storage_failed');
            }

            return [
                'storage_path' => $storagePath,
                'sha256' => hash_file('sha256', Storage::disk('local')->path($storagePath)),
                'archive_size' => filesize(Storage::disk('local')->path($storagePath)),
            ];
        } finally {
            unlink($temporaryPath);
        }
    }
}
