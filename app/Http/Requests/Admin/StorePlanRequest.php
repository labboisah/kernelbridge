<?php

namespace App\Http\Requests\Admin;

use App\Models\Plan;

final class StorePlanRequest extends PlanRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Plan::class) === true;
    }
}
