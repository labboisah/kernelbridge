<?php

namespace App\Http\Controllers\PublicSite;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

final class PageController extends Controller
{
    public function services(): Response
    {
        return Inertia::render('Public/Services');
    }

    public function about(): Response
    {
        return Inertia::render('Public/About');
    }

    public function licensingDocs(): Response
    {
        return Inertia::render('Public/Docs/Licensing');
    }
}
