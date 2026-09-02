<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

final class StaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        $staff = $this->route('staff');

        return $staff instanceof User ? $this->user()?->can('update', $staff) === true : $this->user()?->can('create', User::class) === true;
    }

    public function rules(): array
    {
        $staff = $this->route('staff');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($staff?->id)],
            'password' => [$staff ? 'nullable' : 'required', 'confirmed', Password::defaults()],
            'role' => ['required', Rule::in(UserRole::administrative())],
            'status' => ['required', Rule::in(['active', 'suspended'])],
        ];
    }
}
