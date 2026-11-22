<?php

namespace App\Http\Controllers\Partner;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Partners\QuotationPlanContents;
use App\Domain\Sales\Services\QuotationCalculator;
use App\Http\Controllers\Controller;
use App\Models\BillingSetting;
use App\Models\Lead;
use App\Models\PartnerQuotation;
use App\Models\Plan;
use App\Models\Product;
use App\Models\ProspectiveCustomer;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

final class QuotationController extends Controller
{
    private function authorizePartner(Request $request): void
    {
        abort_unless($request->user()->isApprovedAgent(), 403, 'An approved sales agent application is required.');
    }

    public function index(Request $request, QuotationPlanContents $contents)
    {
        $this->authorizePartner($request);
        $partnerId = $request->user()->id;

        return Inertia::render('Partner/Quotations', [
            'quotations' => PartnerQuotation::where('partner_user_id', $partnerId)->latest()->paginate(20),
            'prospects' => ProspectiveCustomer::where('partner_user_id', $partnerId)->orderBy('name')->get(['uuid', 'name', 'organization']),
            'leads' => Lead::where('partner_user_id', $partnerId)->orderBy('name')->get(['uuid', 'name', 'organization']),
            'products' => Product::published()->get(['id', 'name']),
            'plans' => Plan::publiclyAvailable()->whereNotNull('price')
                ->whereHas('product', fn ($query) => $query->where('status', 'active'))
                ->with(['product', 'features', 'limits'])
                ->get(['id', 'uuid', 'product_id', 'name', 'price', 'currency', 'billing_cycle'])
                ->map(fn (Plan $plan) => [
                    'uuid' => $plan->uuid, 'product_id' => $plan->product_id, 'name' => $plan->name,
                    'price' => $plan->price, 'currency' => $plan->currency, 'billing_cycle' => $plan->billing_cycle->value,
                    ...$contents->forPlan($plan),
                ]),
            'vatRate' => BillingSetting::vatRate(),
        ]);
    }

    public function store(Request $request, QuotationCalculator $calculator, AuditRecorder $audit, QuotationPlanContents $contents)
    {
        $this->authorizePartner($request);
        $data = $request->validate([
            'recipient_type' => ['required', Rule::in(['manual', 'pcr', 'crm'])],
            'recipient_uuid' => ['required_unless:recipient_type,manual', 'nullable', 'uuid'],
            'recipient_name' => ['required_if:recipient_type,manual', 'nullable', 'string', 'max:255'],
            'recipient_organization' => ['nullable', 'string', 'max:255'],
            'recipient_email' => ['nullable', 'email', 'max:255'],
            'recipient_phone' => ['nullable', 'string', 'max:40'],
            'recipient_location' => ['nullable', 'string', 'max:255'],
            'product_id' => ['required', 'integer'],
            'plan_uuid' => ['required', 'uuid'],
            'description' => ['required', 'string', 'max:5000'],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:today'],
            'terms' => ['nullable', 'string', 'max:5000'],
        ]);
        $recipient = match ($data['recipient_type']) {
            'pcr' => ProspectiveCustomer::where('partner_user_id', $request->user()->id)->where('uuid', $data['recipient_uuid'])->firstOrFail(),
            'crm' => Lead::where('partner_user_id', $request->user()->id)->where('uuid', $data['recipient_uuid'])->firstOrFail(),
            default => null,
        };
        $plan = Plan::publiclyAvailable()->whereNotNull('price')->where('uuid', $data['plan_uuid'])
            ->where('product_id', $data['product_id'])
            ->whereHas('product', fn ($query) => $query->where('status', 'active'))
            ->with(['product', 'features', 'limits'])->first();
        if (! $plan) {
            throw ValidationException::withMessages(['plan_uuid' => 'Choose an available plan for the selected product.']);
        }
        $vatRate = BillingSetting::vatRate();
        if ((float) $vatRate < 0 || (float) $vatRate > 100) {
            throw ValidationException::withMessages(['plan_uuid' => 'The configured VAT rate is invalid. Contact an administrator.']);
        }
        if ((float) $plan->price * (int) $data['quantity'] > 1000000000) {
            throw ValidationException::withMessages(['quantity' => 'The quotation subtotal cannot exceed 1,000,000,000.']);
        }
        $totals = $calculator->calculate([[
            'quantity' => (string) $data['quantity'],
            'unit_price' => $plan->price,
        ]], $vatRate);
        $planContents = $contents->forPlan($plan);
        DB::transaction(function () use ($request, $data, $recipient, $plan, $vatRate, $totals, $planContents, $audit): void {
            $quotation = PartnerQuotation::create([
                'partner_user_id' => $request->user()->id,
                'prospective_customer_id' => $recipient instanceof ProspectiveCustomer ? $recipient->id : null,
                'lead_id' => $recipient instanceof Lead ? $recipient->id : null,
                'product_id' => $plan->product_id,
                'plan_id' => $plan->id,
                'partner_name' => $request->user()->name,
                'recipient_name' => $recipient ? $recipient->name : $data['recipient_name'],
                'recipient_organization' => $recipient ? $recipient->organization : ($data['recipient_organization'] ?? null),
                'recipient_email' => $recipient ? $recipient->email : ($data['recipient_email'] ?? null),
                'recipient_phone' => $recipient ? $recipient->phone : ($data['recipient_phone'] ?? null),
                'recipient_location' => $recipient ? $recipient->location : ($data['recipient_location'] ?? null),
                'product_name' => $plan->product->name,
                'plan_name' => $plan->name,
                'billing_cycle' => $plan->billing_cycle->value,
                'currency' => $plan->currency,
                'description' => $data['description'],
                'quantity' => $data['quantity'],
                'unit_price' => $plan->price,
                'vat_rate' => $vatRate,
                'subtotal' => $totals['subtotal'],
                'tax' => $totals['tax'],
                'total' => $totals['total'],
                'issue_date' => today(),
                'expires_at' => $data['expires_at'] ?? null,
                'plan_modules' => $planContents['modules'],
                'plan_features' => $planContents['features'],
                'terms' => $data['terms'] ?? null,
            ]);
            $quotation->update(['quotation_number' => sprintf('KBT-PQT-%s-%06d', $quotation->issue_date->format('Y'), $quotation->id)]);
            $audit->record('partner.quotation.created', $request->user(), ['quotation_uuid' => $quotation->uuid, 'total' => $quotation->total, 'currency' => $quotation->currency], $quotation);

        });

        return redirect()->route('partner.quotations.index')->with('success', 'Quotation created. Download its PDF from the list below.');
    }

    public function pdf(Request $request, string $quotation)
    {
        $this->authorizePartner($request);
        $record = PartnerQuotation::where('partner_user_id', $request->user()->id)->where('uuid', $quotation)->firstOrFail();
        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $pdf = new Dompdf($options);
        $pdf->loadHtml(view('partner.quotation-pdf', ['quotation' => $record])->render(), 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$record->quotation_number.'.pdf"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
