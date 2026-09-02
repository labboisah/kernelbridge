<?php

namespace App\Http\Requests\Admin;

final class UpdatePlanRequest extends PlanRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('plan')) === true;
    }
}
