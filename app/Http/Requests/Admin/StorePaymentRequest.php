<?php

namespace App\Http\Requests\Admin;

use App\Enums\PaymentMethod;
use App\Models\Payment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StorePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Payment::class) === true;
    }

    public function rules(): array
    {
        return ['customer_id' => ['required', 'exists:customers,id'], 'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'], 'currency' => ['required', 'string', 'size:3'], 'payment_method' => ['required', Rule::enum(PaymentMethod::class)], 'transaction_reference' => ['required', 'string', 'max:255', 'unique:payments,transaction_reference'], 'payment_date' => ['required', 'date', 'before_or_equal:today'], 'proof_of_payment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:5120'], 'notes' => ['nullable', 'string', 'max:5000']];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['currency' => strtoupper((string) $this->input('currency', 'NGN'))]);
    }
}
