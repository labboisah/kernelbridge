<?php

namespace App\Http\Requests\Admin;

use App\Enums\ProductLinkType;
use App\Enums\ProductMediaType;
use App\Enums\ProductStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

abstract class ProductRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $product = $this->route('product');
        $licensingModeChanged = $this->has('licensing_mode')
            && ($product?->licensing_mode ?? 'legacy') !== $this->input('licensing_mode');

        return [
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'alpha_dash', 'max:50', Rule::unique('products', 'code')->ignore($product)],
            'slug' => ['required', 'alpha_dash', 'max:120', Rule::unique('products', 'slug')->ignore($product)],
            'category' => ['required', 'string', 'max:120'],
            'industry' => ['required', 'string', 'max:120'],
            'tagline' => ['required', 'string', 'max:255'],
            'hero_description' => ['nullable', 'string', 'max:1000'],
            'short_description' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:10000'],
            'icon' => ['nullable', 'string', 'max:80'],
            'logo' => ['nullable', 'string', 'max:2048'],
            'cover_image' => ['nullable', 'string', 'max:2048'],
            'demo_url' => ['nullable', 'url:http,https', 'max:2048'],
            'website_url' => ['nullable', 'url:http,https', 'max:2048'],
            'status' => ['required', Rule::enum(ProductStatus::class)],
            'licensing_mode' => ['sometimes', 'string', Rule::in(['legacy', 'direct', 'platform_account', 'hybrid'])],
            'licensing_mode_reason' => [Rule::requiredIf($licensingModeChanged), 'nullable', 'string', 'max:1000'],
            'runtime_type' => ['sometimes', 'string', Rule::in(['unknown', 'laravel', 'typescript_desktop', 'browser', 'other'])],
            'repository_url' => ['nullable', 'url:https', 'max:512', 'regex:/^https:\/\/github\.com\/[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+(?:\.git)?$/i'],
            'repository_visibility' => [Rule::requiredIf(filled($this->input('repository_url'))), 'nullable', Rule::in(['public', 'private'])],
            'github_installation_id' => [Rule::requiredIf($this->input('repository_visibility') === 'private'), 'nullable', 'regex:/^\d{1,32}$/'],
            'stable_release_version' => [Rule::requiredIf(filled($this->input('repository_url'))), 'nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9][A-Za-z0-9.+_-]*$/'],
            'stable_release_tag' => [Rule::requiredIf(filled($this->input('repository_url'))), 'nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/', 'not_regex:/\.\.|@\{/'],
            'installation_guide_markdown' => ['nullable', 'string', 'max:50000'],
            'is_featured' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:65535'],
            'metadata' => ['nullable', 'array'],
            'seo_title' => ['nullable', 'string', 'max:255'],
            'seo_description' => ['nullable', 'string', 'max:320'],
            'features' => ['nullable', 'array', 'max:100'],
            'features.*.name' => ['required', 'string', 'max:180'],
            'features.*.description' => ['nullable', 'string', 'max:1000'],
            'features.*.icon' => ['nullable', 'string', 'max:80'],
            'features.*.is_active' => ['required', 'boolean'],
            'media' => ['nullable', 'array', 'max:100'],
            'media.*.type' => ['required', Rule::enum(ProductMediaType::class)],
            'media.*.title' => ['nullable', 'string', 'max:180'],
            'media.*.url' => ['required', 'url:http,https', 'max:2048'],
            'media.*.alt_text' => ['nullable', 'string', 'max:255'],
            'media.*.is_active' => ['required', 'boolean'],
            'links' => ['nullable', 'array', 'max:50'],
            'links.*.type' => ['required', Rule::enum(ProductLinkType::class)],
            'links.*.label' => ['required', 'string', 'max:120'],
            'links.*.url' => ['required', 'url:http,https', 'max:2048'],
            'links.*.is_active' => ['required', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => strtoupper((string) $this->input('code')),
            'category' => $this->input('category') ?: $this->input('industry'),
            'is_featured' => $this->boolean('is_featured'),
        ]);
    }
}
