<?php

namespace App\Http\Requests\Admin;

use App\Enums\DemoRequestStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateDemoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('demo.manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['status' => ['required', Rule::enum(DemoRequestStatus::class)], 'scheduled_at' => ['nullable', 'date', 'required_if:status,scheduled'], 'assigned_to' => ['nullable', 'integer', 'exists:users,id']];
    }
}
