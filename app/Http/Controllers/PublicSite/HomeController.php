<?php

namespace App\Http\Controllers\PublicSite;

use App\Http\Controllers\Controller;
use App\Http\Resources\PublicSite\ProductResource;
use App\Models\Product;
use Inertia\Inertia;
use Inertia\Response;

final class HomeController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Public/Home', [
            'partnerTypes' => config('partners'),
            'products' => ProductResource::collection(Product::query()->featured()->get())->resolve(),
            'activeProductCount' => Product::query()->featured()->count(),
        ]);
    }
}
