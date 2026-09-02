<?php

namespace App\Http\Requests\Admin;

use App\Models\SupportTeam;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SupportTeamRequest extends FormRequest
{
    public function authorize(): bool
    {
        $team = $this->route('support_team');

        return $team instanceof SupportTeam ? $this->user()?->can('update', $team) === true : $this->user()?->can('create', SupportTeam::class) === true;
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:120'], 'slug' => ['required', 'alpha_dash', 'max:120', Rule::unique('support_teams', 'slug')->ignore($this->route('support_team')?->id)], 'description' => ['nullable', 'string', 'max:1000'], 'is_active' => ['required', 'boolean'], 'sort_order' => ['required', 'integer', 'min:0', 'max:10000']];
    }
}
