<?php

namespace App\Http\Requests;

use App\Enums\SupportCategory;
use App\Enums\SupportPriority;
use App\Models\SupportTicket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreSupportTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', SupportTicket::class) === true;
    }

    public function rules(): array
    {
        return ['product_uuid' => ['nullable', 'uuid', 'exists:products,uuid'], 'team_uuid' => ['required_without:category', 'nullable', 'uuid', Rule::exists('support_teams', 'uuid')->where('is_active', true)], 'category' => ['required_without:team_uuid', 'nullable', Rule::enum(SupportCategory::class)], 'subject' => ['required', 'string', 'min:5', 'max:255'], 'priority' => ['required', Rule::enum(SupportPriority::class)], 'message' => ['required', 'string', 'min:10', 'max:20000'], 'attachments' => ['nullable', 'array', 'max:5'], 'attachments.*' => ['file', 'mimes:pdf,txt,jpg,jpeg,png,webp,zip', 'max:10240']];
    }
}
