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

        $data = (new ProductResource($product->load(['productFeatures', 'media', 'links'])))->resolve();
        $data['repository'] = $this->deliverySettings($product);
        $data['release'] = $product->releases()
            ->where('version', $product->stable_release_version)
            ->where('tag', $product->stable_release_tag)
            ->first()?->only(['version', 'tag', 'status', 'sha256', 'archive_size', 'failure_code', 'built_at']);

        return Inertia::render('Admin/Products/Show', ['product' => $data]);
    }

    public function edit(Product $product): Response
    {
        $this->authorize('update', $product);

        $data = (new ProductResource($product->load(['productFeatures', 'media', 'links'])))->resolve();
        $data['licensing_mode'] = $product->licensing_mode;
        $data['runtime_type'] = $product->runtime_type;
        $data['licensing_policy_revision'] = $product->licensing_policy_revision;
        $data['repository_url'] = $product->repository_url;
        $data['repository_visibility'] = $product->repository_visibility;
        $data['github_installation_id'] = $product->github_installation_id;
        $data['stable_release_version'] = $product->stable_release_version;
        $data['stable_release_tag'] = $product->stable_release_tag;
        $data['installation_guide_markdown'] = $product->installation_guide_markdown;

        return Inertia::render('Admin/Products/Edit', ['product' => $data]);
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

    /** @return array<string, mixed> */
    private function deliverySettings(Product $product): array
    {
        return [
            'repository_url' => $product->repository_url,
            'repository_visibility' => $product->repository_visibility,
            'github_installation_id' => $product->github_installation_id,
            'stable_release_version' => $product->stable_release_version,
            'stable_release_tag' => $product->stable_release_tag,
            'installation_guide_markdown' => $product->installation_guide_markdown,
        ];
    }
}
