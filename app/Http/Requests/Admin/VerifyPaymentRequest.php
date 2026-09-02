<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

final class VerifyPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('verify', $this->route('payment')) === true;
    }

    public function rules(): array
    {
        return ['allocations' => ['required', 'array', 'min:1'], 'allocations.*.invoice_id' => ['required', 'integer', 'distinct', 'exists:invoices,id'], 'allocations.*.amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2']];
    }
}
