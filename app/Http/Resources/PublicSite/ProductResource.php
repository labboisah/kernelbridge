<?php

namespace App\Http\Resources\PublicSite;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ProductResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $features = $this->relationLoaded('productFeatures') && $this->productFeatures->isNotEmpty()
            ? $this->productFeatures->where('is_active', true)->pluck('name')->values()->all()
            : ($this->features ?? []);
        $screenshots = $this->relationLoaded('media') && $this->media->isNotEmpty()
            ? $this->media->where('is_active', true)->where('type.value', 'screenshot')->pluck('url')->values()->all()
            : ($this->screenshots ?? []);

        return ['id' => $this->uuid ?? $this->public_id, 'public_id' => $this->public_id, 'code' => $this->code, 'name' => $this->name, 'slug' => $this->slug, 'tagline' => $this->tagline, 'hero_description' => $this->hero_description, 'short_description' => $this->short_description, 'description' => $this->description, 'category' => $this->category ?? $this->industry, 'seo_title' => $this->seo_title, 'seo_description' => $this->seo_description, 'industry' => $this->industry, 'icon' => $this->icon, 'logo' => $this->logo, 'cover_image' => $this->cover_image, 'features' => $features, 'feature_records' => $this->whenLoaded('productFeatures'), 'benefits' => $this->benefits, 'workflow' => $this->workflow, 'platform_components' => $this->platform_components, 'screenshots' => $screenshots, 'media' => $this->whenLoaded('media'), 'links' => $this->whenLoaded('links'), 'plans' => $this->plans, 'demo_url' => $this->demo_url, 'website_url' => $this->website_url, 'is_featured' => $this->is_featured, 'metadata' => $this->metadata, 'sort_order' => $this->sort_order, 'status' => $this->status->value];
    }
}
