<?php

namespace App\Domain\Products\Services;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final readonly class ProductManager
{
    public function __construct(private AuditRecorder $audit) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, User $actor): Product
    {
        return DB::transaction(function () use ($data, $actor): Product {
            $product = Product::query()->create($this->productAttributes($data));
            $this->synchronizeRelations($product, $data);
            $this->audit->record('product.created', $actor, ['product_uuid' => $product->uuid, 'after' => $product->fresh()->toArray()]);

            return $product;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Product $product, array $data, User $actor): Product
    {
        return DB::transaction(function () use ($product, $data, $actor): Product {
            $before = $product->load(['productFeatures', 'media', 'links'])->toArray();
            $product->update($this->productAttributes($data));
            $this->synchronizeRelations($product, $data);
            $this->audit->record('product.updated', $actor, ['product_uuid' => $product->uuid, 'before' => $before, 'after' => $product->fresh()->load(['productFeatures', 'media', 'links'])->toArray()]);

            return $product;
        });
    }

    public function delete(Product $product, User $actor): void
    {
        DB::transaction(function () use ($product, $actor): void {
            $snapshot = $product->load(['productFeatures', 'media', 'links'])->toArray();
            $product->delete();
            $this->audit->record('product.deleted', $actor, ['product_uuid' => $product->uuid, 'before' => $snapshot]);
        });
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function productAttributes(array $data): array
    {
        $attributes = Arr::except($data, ['features', 'media', 'links']);
        $attributes['features'] = collect($data['features'] ?? [])->pluck('name')->values()->all();
        $attributes['screenshots'] = collect($data['media'] ?? [])->where('type', 'screenshot')->pluck('url')->values()->all();

        foreach (($data['links'] ?? []) as $link) {
            if ($link['type'] === 'demo') {
                $attributes['demo_url'] = $link['url'];
            }
            if ($link['type'] === 'website') {
                $attributes['website_url'] = $link['url'];
            }
        }

        return $attributes;
    }

    /** @param array<string, mixed> $data */
    private function synchronizeRelations(Product $product, array $data): void
    {
        $product->productFeatures()->delete();
        foreach (array_values($data['features'] ?? []) as $index => $feature) {
            $product->productFeatures()->create([...$feature, 'sort_order' => $index]);
        }

        $product->media()->delete();
        foreach (array_values($data['media'] ?? []) as $index => $media) {
            $product->media()->create([...$media, 'sort_order' => $index]);
        }

        $product->links()->delete();
        foreach (array_values($data['links'] ?? []) as $index => $link) {
            $product->links()->create([...$link, 'sort_order' => $index]);
        }
    }
}
