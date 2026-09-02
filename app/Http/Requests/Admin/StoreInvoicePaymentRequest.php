<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

final class StoreInvoicePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('recordPayment', $this->route('invoice')) === true;
    }

    public function rules(): array
    {
        return ['amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'], 'reference' => ['required', 'string', 'max:255', 'unique:invoice_payments,reference'], 'method' => ['nullable', 'string', 'max:80'], 'paid_at' => ['required', 'date'], 'metadata' => ['nullable', 'array']];
    }
}
