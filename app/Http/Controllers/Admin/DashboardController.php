<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Reporting\Services\ExecutiveDashboardService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DashboardRequest;
use Carbon\CarbonImmutable;
use Inertia\Inertia;
use Inertia\Response;

final class DashboardController extends Controller
{
    public function __invoke(DashboardRequest $request, ExecutiveDashboardService $dashboard): Response
    {
        [$from, $to] = $this->dates($request->validated());

        return Inertia::render('Admin/Dashboard', [
            ...$dashboard->report($from, $to),
            'filters' => ['range' => $request->validated('range'), 'from' => $from->toDateString(), 'to' => $to->toDateString()],
        ]);
    }

    private function dates(array $filters): array
    {
        $today = CarbonImmutable::today();

        return match ($filters['range']) {
            '30_days' => [$today->subDays(29), $today],
            'quarter' => [$today->startOfQuarter(), $today],
            'custom' => [CarbonImmutable::parse($filters['from']), CarbonImmutable::parse($filters['to'])],
            default => [$today->startOfYear(), $today],
        };
    }
}
