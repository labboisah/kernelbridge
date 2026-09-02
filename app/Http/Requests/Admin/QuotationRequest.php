<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

abstract class QuotationRequest extends FormRequest
{
    public function rules(): array
    {
        return ['customer_id' => ['required', 'integer', 'exists:customers,id'], 'issue_date' => ['required', 'date'], 'expiry_date' => ['required', 'date', 'after_or_equal:issue_date'], 'currency' => ['required', 'string', 'size:3'], 'notes' => ['nullable', 'string', 'max:5000'], 'terms' => ['nullable', 'string', 'max:10000'], 'items' => ['required', 'array', 'min:1', 'max:100'], 'items.*.product_id' => ['nullable', 'integer', 'exists:products,id'], 'items.*.plan_id' => ['nullable', 'integer', 'exists:plans,id'], 'items.*.description' => ['required', 'string', 'max:1000'], 'items.*.quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,2'], 'items.*.unit_price' => ['required', 'numeric', 'min:0', 'decimal:0,2'], 'items.*.discount' => ['required', 'numeric', 'min:0', 'decimal:0,2'], 'items.*.tax' => ['required', 'numeric', 'min:0', 'decimal:0,2']];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['currency' => strtoupper((string) $this->input('currency', 'NGN'))]);
    }
}
