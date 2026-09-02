<?php

namespace App\Http\Requests\Admin;

use App\Enums\BillingCycle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

abstract class PlanRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $plan = $this->route('plan');
        $productId = $plan?->product_id ?? $this->route('product')?->id;

        return [
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'alpha_dash', 'max:120', Rule::unique('plans')->where('product_id', $productId)->ignore($plan)],
            'description' => ['nullable', 'string', 'max:3000'],
            'billing_cycle' => ['required', Rule::enum(BillingCycle::class)],
            'price' => ['nullable', 'numeric', 'min:0', 'max:9999999999999.99', 'required_unless:billing_cycle,custom'],
            'currency' => ['required', 'string', 'size:3'],
            'setup_fee' => ['required', 'numeric', 'min:0', 'max:9999999999999.99'],
            'trial_days' => ['required', 'integer', 'min:0', 'max:3650'],
            'grace_period_days' => ['required', 'integer', 'min:0', 'max:3650'],
            'is_active' => ['required', 'boolean'],
            'is_public' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:65535'],
            'metadata' => ['nullable', 'array'],
            'features' => ['nullable', 'array', 'max:200'],
            'features.*.feature_key' => ['required', 'alpha_dash', 'max:120', 'distinct'],
            'features.*.feature_name' => ['required', 'string', 'max:180'],
            'features.*.enabled' => ['required', 'boolean'],
            'features.*.limit_value' => ['nullable', 'string', 'max:255'],
            'limits' => ['nullable', 'array', 'max:200'],
            'limits.*.limit_key' => ['required', 'alpha_dash', 'max:120', 'distinct'],
            'limits.*.limit_name' => ['required', 'string', 'max:180'],
            'limits.*.limit_value' => ['nullable', 'string', 'max:255'],
            'limits.*.metadata' => ['nullable', 'array'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'currency' => strtoupper((string) $this->input('currency', 'USD')),
            'is_active' => $this->boolean('is_active'),
            'is_public' => $this->boolean('is_public'),
        ]);
    }
}
