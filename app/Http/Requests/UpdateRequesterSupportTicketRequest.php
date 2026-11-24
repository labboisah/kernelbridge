<?php

namespace App\Http\Requests;

use App\Enums\SupportPriority;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateRequesterSupportTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('updateByRequester', $this->route('ticket')) === true;
    }

    public function rules(): array
    {
        return [
            'product_uuid' => ['nullable', 'uuid', 'exists:products,uuid'],
            'team_uuid' => ['required', 'uuid', Rule::exists('support_teams', 'uuid')->where('is_active', true)],
            'subject' => ['required', 'string', 'min:5', 'max:255'],
            'priority' => ['required', Rule::enum(SupportPriority::class)],
            'message' => ['required', 'string', 'min:10', 'max:20000'],
        ];
    }
}
