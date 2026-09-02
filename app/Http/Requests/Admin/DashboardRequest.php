<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

final class DashboardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['range' => $this->input('range', 'year')]);
    }

    public function rules(): array
    {
        return [
            'range' => ['required', 'in:30_days,quarter,year,custom'],
            'from' => ['nullable', 'required_if:range,custom', 'date'],
            'to' => ['nullable', 'required_if:range,custom', 'date', 'after_or_equal:from'],
        ];
    }
}
