<?php

namespace App\Http\Requests\Admin;

use App\Models\Customer;

final class StoreCustomerRequest extends CustomerRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Customer::class) === true;
    }
}
