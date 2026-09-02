<?php

namespace App\Http\Requests\Admin;

use App\Models\Product;

final class StoreProductRequest extends ProductRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Product::class) === true;
    }
}
