<?php

namespace App\Domain\Partners;

use App\Enums\UserRole;
use App\Models\BillingSetting;
use App\Models\PartnerApplication;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class PartnerQualificationService
{
    public function forPartner(int $partnerId): array
    {
        $count = DB::table('customers')
            ->where('partner_user_id', $partnerId)
            ->where('status', 'active')
            ->count();

        return $this->qualification($count, BillingSetting::findOrFail(1)->partner_network_min_active_clients);
    }

    public function approvedPartnerQualifications(): Collection
    {
        $applications = PartnerApplication::query()
            ->with('user:id,name,email')
            ->where('type', 'sales_agent')
            ->where('status', 'approved')
            ->whereHas('user', fn ($query) => $query->role(UserRole::Agent->value))
            ->get(['id', 'user_id', 'partner_category']);

        return $this->qualifyApplications($applications);
    }

    public function approvedPartnerPage(int $perPage = 20): LengthAwarePaginator
    {
        $applications = PartnerApplication::query()
            ->with('user:id,name,email')
            ->where('type', 'sales_agent')
            ->where('status', 'approved')
            ->whereHas('user', fn ($query) => $query->role(UserRole::Agent->value))
            ->latest('reviewed_at')
            ->paginate($perPage, ['id', 'user_id', 'partner_category'], 'partner_page');

        $applications->setCollection($this->qualifyApplications($applications->getCollection()));

        return $applications;
    }

    private function qualifyApplications(Collection $applications): Collection
    {
        $counts = DB::table('customers')
            ->whereIn('partner_user_id', $applications->pluck('user_id'))
            ->where('status', 'active')
            ->select('partner_user_id')
            ->selectRaw('COUNT(*) as active_clients')
            ->groupBy('partner_user_id')
            ->pluck('active_clients', 'partner_user_id');
        $minimum = BillingSetting::findOrFail(1)->partner_network_min_active_clients;

        return $applications->map(function (PartnerApplication $application) use ($counts, $minimum): array {
            $activeClients = (int) $counts->get($application->user_id, 0);

            return [
                'user_id' => $application->user_id,
                'name' => $application->user->name,
                'email' => $application->user->email,
                'partner_category' => $application->partner_category,
                ...$this->qualification($activeClients, $minimum),
            ];
        });
    }

    private function qualification(int $activeClients, int $minimum): array
    {
        [$level, $nextThreshold, $label] = match (true) {
            $activeClients >= 6 => ['elite', null, 'Elite Partner'],
            $activeClients >= 3 => ['premium', 6, 'Premium Partner'],
            $activeClients >= 1 => ['qualified', 3, 'Qualified Partner'],
            default => ['standard', 1, 'Standard Partner'],
        };
        $eligible = $activeClients >= $minimum && $level !== 'standard';

        return [
            'level' => $level,
            'label' => $label,
            'active_clients' => $activeClients,
            'next_level_clients' => $nextThreshold,
            'clients_to_next_level' => $nextThreshold === null ? 0 : max(0, $nextThreshold - $activeClients),
            'progress' => $nextThreshold === null ? 100 : min(100, (int) floor($activeClients / $nextThreshold * 100)),
            'network_eligible' => $eligible,
            'network_min_active_clients' => $minimum,
            'clients_to_network_eligibility' => $eligible ? 0 : max(1, $minimum - $activeClients),
        ];
    }
}
