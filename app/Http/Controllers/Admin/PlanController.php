<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Subscriptions\Services\DirectPlanEntitlementMappingService;
use App\Domain\Subscriptions\Services\PlanManager;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePlanRequest;
use App\Http\Requests\Admin\UpdatePlanRequest;
use App\Http\Resources\PlanResource;
use App\Models\EntitlementCatalogRevision;
use App\Models\EntitlementPlanMapping;
use App\Models\Plan;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class PlanController extends Controller
{
    public function index(Product $product): Response
    {
        $this->authorize('viewAny', Plan::class);

        return Inertia::render('Admin/Plans/Index', [
            'product' => ['name' => $product->name, 'slug' => $product->slug],
            'plans' => PlanResource::collection($product->subscriptionPlans()->with(['product', 'features', 'limits'])->get())->resolve(),
        ]);
    }

    public function create(Product $product): Response
    {
        $this->authorize('create', Plan::class);

        return Inertia::render('Admin/Plans/Create', ['product' => ['name' => $product->name, 'slug' => $product->slug]]);
    }

    public function store(StorePlanRequest $request, Product $product, PlanManager $manager): RedirectResponse
    {
        $plan = $manager->create($product, $request->validated(), $request->user());

        return redirect()->route('admin.plans.show', $plan)->with('success', 'Plan created successfully.');
    }

    public function show(Plan $plan): Response
    {
        $this->authorize('view', $plan);

        return Inertia::render('Admin/Plans/Show', ['plan' => (new PlanResource($plan->load(['product', 'features', 'limits'])))->resolve(), 'product' => ['name' => $plan->product->name, 'slug' => $plan->product->slug, 'licensing_mode' => $plan->product->licensing_mode]]);
    }

    public function edit(Plan $plan): Response
    {
        $this->authorize('update', $plan);

        return Inertia::render('Admin/Plans/Edit', ['plan' => (new PlanResource($plan->load(['product', 'features', 'limits'])))->resolve(), 'product' => ['name' => $plan->product->name, 'slug' => $plan->product->slug]]);
    }

    public function update(UpdatePlanRequest $request, Plan $plan, PlanManager $manager): RedirectResponse
    {
        $manager->update($plan, $request->validated(), $request->user());

        return redirect()->route('admin.plans.show', $plan)->with('success', 'Plan updated successfully.');
    }

    public function entitlements(Plan $plan): Response
    {
        $this->authorize('view', $plan);
        abort_unless(in_array($plan->product->licensing_mode, ['direct', 'hybrid'], true), 404);

        $catalog = EntitlementCatalogRevision::query()
            ->where('product_id', $plan->product_id)
            ->orderByDesc('catalog_revision')
            ->first();
        $mapping = EntitlementPlanMapping::query()
            ->where('plan_id', $plan->id)
            ->orderByDesc('source_revision')
            ->first();

        return Inertia::render('Admin/Plans/Entitlements', [
            'product' => ['name' => $plan->product->name, 'slug' => $plan->product->slug, 'code' => $plan->product->code],
            'plan' => ['id' => $plan->uuid, 'name' => $plan->name, 'slug' => $plan->slug],
            'catalog' => $catalog ? [
                'revision' => $catalog->catalog_revision,
                'groups' => collect($catalog->catalog['packages'] ?? [])->map(fn (array $package): array => [
                    'key' => $package['key'],
                    'modules' => collect($package['modules'] ?? [])->map(fn (array $module): array => [
                        'key' => $module['key'],
                        'capabilities' => $module['capabilities'] ?? [],
                    ])->values(),
                ])->values(),
            ] : null,
            'mapping' => $mapping ? [
                'source_revision' => $mapping->source_revision,
                'catalog_revision' => $mapping->catalogRevision()->value('catalog_revision'),
                'grants' => $mapping->grants,
            ] : null,
        ]);
    }

    public function publishEntitlements(
        Request $request,
        Plan $plan,
        DirectPlanEntitlementMappingService $mappings,
    ): RedirectResponse {
        $this->authorize('update', $plan);
        $validated = $request->validate([
            'grants' => ['required', 'array'],
            'grants.*' => ['required', 'array'],
        ]);
        $mapping = $mappings->publish($plan, $validated['grants']);

        return redirect()->route('admin.plans.entitlements', $plan)
            ->with('success', "Published entitlement mapping revision {$mapping->source_revision}.");
    }

    public function destroy(Plan $plan, PlanManager $manager): RedirectResponse
    {
        $this->authorize('delete', $plan);
        $product = $plan->product;
        $manager->delete($plan, request()->user());

        return redirect()->route('admin.products.plans.index', $product)->with('success', 'Plan deleted successfully.');
    }
}
