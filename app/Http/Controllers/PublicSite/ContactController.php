<?php

namespace App\Http\Controllers\PublicSite;

use App\Domain\PublicWebsite\Services\CaptureContactInquiry;
use App\Http\Controllers\Controller;
use App\Http\Requests\PublicSite\StoreContactInquiryRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final class ContactController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Public/Contact');
    }

    public function store(StoreContactInquiryRequest $request, CaptureContactInquiry $capture): RedirectResponse
    {
        $capture->execute($request->validated());

        return back()->with('success', 'Thank you. Our team will respond shortly.');
    }
}
