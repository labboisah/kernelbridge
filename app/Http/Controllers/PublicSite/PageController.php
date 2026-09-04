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

    public function laravelLicensingDocs(): Response
    {
        return Inertia::render('Public/Docs/Licensing/Laravel');
    }

    public function typescriptLicensingDocs(): Response
    {
        return Inertia::render('Public/Docs/Licensing/TypeScript');
    }

    public function springBootLicensingDocs(): Response
    {
        return Inertia::render('Public/Docs/Licensing/SpringBoot');
    }

    public function djangoLicensingDocs(): Response
    {
        return Inertia::render('Public/Docs/Licensing/Django');
    }

    public function mcpLicensingDocs(): Response
    {
        return Inertia::render('Public/Docs/Licensing/Mcp');
    }
}


