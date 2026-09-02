<?php

namespace App\Http\Requests\Admin;

final class UpdateQuotationRequest extends QuotationRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('quotation')) === true;
    }
}
