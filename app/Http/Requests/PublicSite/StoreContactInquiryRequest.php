<?php

namespace App\Http\Requests\PublicSite;

use Illuminate\Foundation\Http\FormRequest;

final class StoreContactInquiryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:120'], 'email' => ['required', 'email:rfc', 'max:255'], 'phone' => ['nullable', 'string', 'max:40'], 'company' => ['nullable', 'string', 'max:160'], 'subject' => ['required', 'string', 'max:180'], 'message' => ['required', 'string', 'max:5000']];
    }
}
