<?php

namespace App\Http\Requests\Admin;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

abstract class CustomerRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(CustomerType::class)],
            'name' => ['required', 'string', 'max:180'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'website' => ['nullable', 'url:http,https', 'max:2048'],
            'tax_id' => ['nullable', 'string', 'max:100'],
            'billing_address' => ['nullable', 'string', 'max:2000'],
            'city' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'country' => ['nullable', 'string', 'size:2'],
            'status' => ['required', Rule::enum(CustomerStatus::class)],
            'source' => ['nullable', 'string', 'max:120'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'metadata' => ['nullable', 'array'],
            'product_ids' => ['nullable', 'array', 'max:100'],
            'product_ids.*' => ['integer', 'distinct', 'exists:products,id'],
            'contacts' => ['nullable', 'array', 'max:100'],
            'contacts.*.name' => ['required', 'string', 'max:180'],
            'contacts.*.job_title' => ['nullable', 'string', 'max:120'],
            'contacts.*.email' => ['nullable', 'email:rfc', 'max:255'],
            'contacts.*.phone' => ['nullable', 'string', 'max:40'],
            'contacts.*.is_primary' => ['required', 'boolean'],
            'addresses' => ['nullable', 'array', 'max:50'],
            'addresses.*.type' => ['required', Rule::in(['billing', 'shipping', 'office', 'other'])],
            'addresses.*.label' => ['nullable', 'string', 'max:120'],
            'addresses.*.address_line_1' => ['required', 'string', 'max:255'],
            'addresses.*.address_line_2' => ['nullable', 'string', 'max:255'],
            'addresses.*.city' => ['nullable', 'string', 'max:120'],
            'addresses.*.state' => ['nullable', 'string', 'max:120'],
            'addresses.*.postal_code' => ['nullable', 'string', 'max:30'],
            'addresses.*.country' => ['required', 'string', 'size:2'],
            'addresses.*.is_primary' => ['required', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['country' => $this->input('country') ? strtoupper((string) $this->input('country')) : null]);
    }
}
