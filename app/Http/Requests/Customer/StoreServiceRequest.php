<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

final class StoreServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['product_uuid' => ['required', 'uuid', 'exists:products,uuid'], 'plan_uuid' => ['required', 'uuid', 'exists:plans,uuid'], 'auto_renew' => ['required', 'boolean']];
    }
}
