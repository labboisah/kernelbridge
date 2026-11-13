<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

final class ProductRelease extends Model
{
    protected $guarded = ['id'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public static function stableFor(Product $product): ?self
    {
        if (! $product->stable_release_version || ! $product->stable_release_tag) {
            return null;
        }

        return self::query()
            ->where('product_id', $product->id)
            ->where('version', $product->stable_release_version)
            ->where('tag', $product->stable_release_tag)
            ->where('status', 'ready')
            ->first();
    }

    public function downloadName(): string
    {
        $version = preg_replace('/[^A-Za-z0-9]+/', '-', $this->version) ?? '';

        return Str::slug($this->product->code).'-'.trim($version, '-').'.zip';
    }
}
