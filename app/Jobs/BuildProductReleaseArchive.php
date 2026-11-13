<?php

namespace App\Jobs;

use App\Domain\Products\Exceptions\ProductArchiveBuildException;
use App\Domain\Products\Services\GitHubProductArchiveBuilder;
use App\Models\ProductRelease;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class BuildProductReleaseArchive implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public function __construct(public readonly int $releaseId) {}

    public function handle(GitHubProductArchiveBuilder $builder): void
    {
        $release = ProductRelease::query()->with('product')->findOrFail($this->releaseId);
        if ($release->status !== 'building') {
            return;
        }

        try {
            $archive = $builder->build($release);
            $release->update([...$archive, 'status' => 'ready', 'failure_code' => null, 'built_at' => now()]);
        } catch (Throwable $exception) {
            report($exception);
            $release->update([
                'status' => 'failed',
                'failure_code' => $exception instanceof ProductArchiveBuildException ? $exception->failureCode : 'release_build_failed',
            ]);
        }
    }
}
