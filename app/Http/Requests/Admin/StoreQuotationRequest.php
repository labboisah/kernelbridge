<?php

namespace App\Http\Requests\Admin;

use App\Models\Quotation;

final class StoreQuotationRequest extends QuotationRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Quotation::class) === true;
    }
}
