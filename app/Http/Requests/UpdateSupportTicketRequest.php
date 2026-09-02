<?php

namespace App\Http\Requests;

use App\Enums\SupportPriority;
use App\Enums\SupportStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateSupportTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage', $this->route('ticket')) === true;
    }

    public function rules(): array
    {
        return ['assigned_to' => ['nullable', 'integer', 'exists:users,id'], 'priority' => ['sometimes', Rule::enum(SupportPriority::class)], 'status' => ['sometimes', Rule::enum(SupportStatus::class)]];
    }
}
