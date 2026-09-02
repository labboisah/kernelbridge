<?php

namespace App\Http\Requests\PublicSite;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreDemoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'product_id' => ['nullable', Rule::exists('products', 'public_id')], 'name' => ['required', 'string', 'max:120'],
            'organization' => ['required', 'string', 'max:160'], 'email' => ['required', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'], 'location' => ['nullable', 'string', 'max:180'],
            'organization_type' => ['required', Rule::in(['school', 'university', 'healthcare', 'business', 'hospitality', 'government', 'nonprofit', 'other'])],
            'number_of_users' => ['nullable', 'integer', 'min:1', 'max:10000000'],
            'preferred_demo_date' => ['nullable', 'date', 'after_or_equal:today'], 'preferred_demo_time' => ['nullable', 'date_format:H:i'],
            'message' => ['nullable', 'string', 'max:5000'], 'source' => ['nullable', 'string', 'max:120'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'organization' => $this->input('organization', $this->input('company')),
            'organization_type' => $this->input('organization_type', 'other'),
            'number_of_users' => $this->input('number_of_users', is_numeric($this->input('team_size')) ? $this->input('team_size') : null),
            'source' => $this->input('source', 'Website'),
        ]);
    }
}
