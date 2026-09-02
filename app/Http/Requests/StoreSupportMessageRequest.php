<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreSupportMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        $ability = $this->boolean('internal_note') ? 'note' : 'reply';

        return $this->user()?->can($ability, $this->route('ticket')) === true;
    }

    public function rules(): array
    {
        return ['body' => ['required', 'string', 'min:2', 'max:20000'], 'internal_note' => ['sometimes', 'boolean'], 'attachments' => ['nullable', 'array', 'max:5'], 'attachments.*' => ['file', 'mimes:pdf,txt,jpg,jpeg,png,webp,zip', 'max:10240']];
    }
}
