<?php

namespace App\Http\Requests\Admin;

final class UpdateCustomerRequest extends CustomerRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('customer')) === true;
    }
}
