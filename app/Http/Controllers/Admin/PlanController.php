<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Subscriptions\Services\PlanManager;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePlanRequest;
use App\Http\Requests\Admin\UpdatePlanRequest;
use App\Http\Resources\PlanResource;
use App\Models\Plan;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
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

        return Inertia::render('Admin/Plans/Show', ['plan' => (new PlanResource($plan->load(['product', 'features', 'limits'])))->resolve(), 'product' => ['name' => $plan->product->name, 'slug' => $plan->product->slug]]);
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

    public function destroy(Plan $plan, PlanManager $manager): RedirectResponse
    {
        $this->authorize('delete', $plan);
        $product = $plan->product;
        $manager->delete($plan, request()->user());

        return redirect()->route('admin.products.plans.index', $product)->with('success', 'Plan deleted successfully.');
    }
}
