<?php

namespace App\Http\Controllers\PublicSite;

use App\Domain\PublicWebsite\Services\CaptureDemoRequest;
use App\Http\Controllers\Controller;
use App\Http\Requests\PublicSite\StoreDemoRequest;
use App\Http\Resources\PublicSite\ProductResource;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class DemoController extends Controller
{
    public function create(Request $request): Response
    {
        $selectedProduct = null;
        if ($request->filled('product')) {
            $selectedProduct = Product::query()->published()->where('slug', $request->string('product'))->firstOrFail();
        }

        return Inertia::render('Public/Demo', [
            'products' => ProductResource::collection(Product::query()->published()->get())->resolve(),
            'selectedProductId' => $selectedProduct?->public_id,
        ]);
    }

    public function store(StoreDemoRequest $request, CaptureDemoRequest $capture): RedirectResponse
    {
        $capture->execute($request->validated());

        return back()->with('success', 'Your demo request has been received. We will contact you shortly.');
    }
}
