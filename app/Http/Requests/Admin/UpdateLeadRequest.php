<?php

namespace App\Http\Requests\Admin;

use App\Enums\LeadStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('lead')) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(LeadStatus::class)], 'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'follow_up_date' => ['nullable', 'date'], 'product_id' => ['nullable', 'integer', 'exists:products,id'],
        ];
    }
}
