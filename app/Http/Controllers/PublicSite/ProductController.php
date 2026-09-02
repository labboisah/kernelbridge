<?php

namespace App\Http\Controllers\PublicSite;

use App\Http\Controllers\Controller;
use App\Http\Resources\PublicSite\ProductResource;
use App\Models\Product;
use Inertia\Inertia;
use Inertia\Response;

final class ProductController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Public/Products/Index', ['products' => ProductResource::collection(Product::query()->with(['productFeatures', 'media', 'links'])->published()->get())->resolve()]);
    }

    public function show(Product $product): Response
    {
        abort_unless($product->status->value === 'active', 404);

        $product->load(['productFeatures', 'media', 'links']);

        return Inertia::render('Public/Products/Show', ['product' => (new ProductResource($product))->resolve()]);
    }
}
