<?php

namespace App\Http\Requests\Admin;

final class UpdateProductRequest extends ProductRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('product')) === true;
    }
}
