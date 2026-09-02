<?php

namespace App\Http\Controllers\PublicSite;

use App\Http\Controllers\Controller;
use App\Http\Resources\PlanResource;
use App\Http\Resources\PublicSite\ProductResource;
use App\Models\Product;
use Inertia\Inertia;
use Inertia\Response;

final class PricingController extends Controller
{
    public function show(Product $product): Response
    {
        abort_unless($product->status->value === 'active', 404);

        $plans = $product->subscriptionPlans()->with(['product', 'features', 'limits'])->publiclyAvailable()->get();

        return Inertia::render('Public/Products/Pricing', [
            'product' => (new ProductResource($product))->resolve(),
            'plans' => PlanResource::collection($plans)->resolve(),
        ]);
    }
}
