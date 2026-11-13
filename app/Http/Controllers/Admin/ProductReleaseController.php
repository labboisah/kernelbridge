<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\BuildProductReleaseArchive;
use App\Models\Product;
use App\Models\ProductRelease;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

final class ProductReleaseController extends Controller
{
    public function store(Product $product): RedirectResponse
    {
        $this->authorize('update', $product);
        abort_unless($product->repository_url && $product->stable_release_version && $product->stable_release_tag, 422, 'Configure a repository and stable release before building.');

        $release = DB::transaction(function () use ($product): ProductRelease {
            $release = ProductRelease::query()->where('product_id', $product->id)
                ->where('version', $product->stable_release_version)
                ->where('tag', $product->stable_release_tag)
                ->lockForUpdate()->first();

            if ($release?->status === 'ready') {
                return $release;
            }

            if (! $release) {
                $release = new ProductRelease([
                    'version' => $product->stable_release_version,
                    'tag' => $product->stable_release_tag,
                ]);
                $release->product()->associate($product);
            }

            $release->fill([
                'status' => 'building',
                'failure_code' => null,
                'requested_by' => request()->user()->id,
                'build_started_at' => now(),
            ])->save();

            return $release;
        });

        if ($release->status === 'building') {
            BuildProductReleaseArchive::dispatch($release->id)->afterCommit();

            return back()->with('success', 'Release archive build queued. Refresh this page to see its status.');
        }

        return back()->with('success', 'The selected stable release archive is already ready.');
    }
}
