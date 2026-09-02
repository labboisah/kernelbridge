<?php

namespace Database\Seeders;

use App\Models\SupportTeam;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

final class SupportTeamSeeder extends Seeder
{
    public function run(): void
    {
        $teams = [
            'technical' => 'Technical product issues and troubleshooting.',
            'billing' => 'Invoices, payments, and account billing.',
            'license' => 'Licence activation and entitlement assistance.',
            'deployment' => 'Installation, hosting, and deployment support.',
            'training' => 'Product onboarding and user training.',
            'general' => 'General enquiries and request triage.',
        ];

        foreach ($teams as $order => $description) {
            $slug = is_string($order) ? $order : '';
            SupportTeam::query()->updateOrCreate(['slug' => $slug], ['name' => Str::headline($slug), 'description' => $description, 'is_active' => true, 'sort_order' => array_search($slug, array_keys($teams), true) + 1]);
        }
    }
}
