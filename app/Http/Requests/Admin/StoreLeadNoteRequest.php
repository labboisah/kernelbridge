<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

final class StoreLeadNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('addNote', $this->route('lead')) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['body' => ['required', 'string', 'max:5000'], 'is_private' => ['required', 'boolean']];
    }
}
