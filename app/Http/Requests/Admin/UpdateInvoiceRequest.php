<?php

namespace App\Http\Requests\Admin;

final class UpdateInvoiceRequest extends InvoiceRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('invoice')) === true;
    }

    public function rules(): array
    {
        $rules = parent::rules();
        $rules['quotation_id'] = array_filter($rules['quotation_id'], fn ($r) => ! is_string($r) || ! str_starts_with($r, 'unique:'));

        return $rules;
    }
}
