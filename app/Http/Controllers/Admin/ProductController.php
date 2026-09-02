<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Products\Services\ProductManager;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreProductRequest;
use App\Http\Requests\Admin\UpdateProductRequest;
use App\Http\Resources\PublicSite\ProductResource;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final class ProductController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', Product::class);

        return Inertia::render('Admin/Products/Index', [
            'products' => ProductResource::collection(Product::query()->with(['productFeatures', 'media', 'links'])->orderBy('sort_order')->get())->resolve(),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Product::class);

        return Inertia::render('Admin/Products/Create');
    }

    public function store(StoreProductRequest $request, ProductManager $manager): RedirectResponse
    {
        $product = $manager->create($request->validated(), $request->user());

        return redirect()->route('admin.products.show', $product)->with('success', 'Product created successfully.');
    }

    public function show(Product $product): Response
    {
        $this->authorize('view', $product);

        return Inertia::render('Admin/Products/Show', ['product' => (new ProductResource($product->load(['productFeatures', 'media', 'links'])))->resolve()]);
    }

    public function edit(Product $product): Response
    {
        $this->authorize('update', $product);

        return Inertia::render('Admin/Products/Edit', ['product' => (new ProductResource($product->load(['productFeatures', 'media', 'links'])))->resolve()]);
    }

    public function update(UpdateProductRequest $request, Product $product, ProductManager $manager): RedirectResponse
    {
        $manager->update($product, $request->validated(), $request->user());

        return redirect()->route('admin.products.show', $product)->with('success', 'Product updated successfully.');
    }

    public function destroy(Product $product, ProductManager $manager): RedirectResponse
    {
        $this->authorize('delete', $product);
        $manager->delete($product, request()->user());

        return redirect()->route('admin.products.index')->with('success', 'Product deleted successfully.');
    }
}
