<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $quotation->quotation_number }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; color: #1e293b; font-size: 11px; }
        h1 { color: #0b3d6e; font-size: 26px; margin-bottom: 4px; }
        h2 { color: #0b3d6e; font-size: 14px; margin-top: 24px; }
        .muted { color: #64748b; }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        th, td { padding: 9px; border-bottom: 1px solid #e2e8f0; text-align: left; vertical-align: top; }
        th { background: #f1f5f9; }
        .amount { text-align: right; white-space: nowrap; }
        .total { font-weight: bold; color: #0b3d6e; }
        .multiline { white-space: pre-wrap; overflow-wrap: break-word; }
    </style>
</head>
<body>
    <h1>Quotation</h1>
    <div>{{ $quotation->quotation_number }}</div>
    <p class="muted">Prepared by {{ $quotation->partner_name }}<br>
        Issued: {{ $quotation->issue_date->format('Y-m-d') }}
        @if ($quotation->expires_at)<br>Valid until: {{ $quotation->expires_at->format('Y-m-d') }}@endif
    </p>
    <h2>Prepared for</h2>
    <div>{{ $quotation->recipient_name }}</div>
    @if ($quotation->recipient_organization)<div>{{ $quotation->recipient_organization }}</div>@endif
    @if ($quotation->recipient_email)<div>{{ $quotation->recipient_email }}</div>@endif
    @if ($quotation->recipient_phone)<div>{{ $quotation->recipient_phone }}</div>@endif
    @if ($quotation->recipient_location)<div>{{ $quotation->recipient_location }}</div>@endif
    <table>
        <thead><tr><th>Product and plan</th><th>Quantity</th><th class="amount">Unit price</th><th class="amount">Subtotal</th></tr></thead>
        <tbody>
            <tr>
                <td><strong>{{ $quotation->product_name }} - {{ $quotation->plan_name }}</strong><br>
                    <span class="muted">Billing cycle: {{ $quotation->billing_cycle }}</span>
                </td>
                <td>{{ $quotation->quantity }}</td>
                <td class="amount">{{ $quotation->currency }} {{ $quotation->unit_price }}</td>
                <td class="amount">{{ $quotation->currency }} {{ $quotation->subtotal }}</td>
            </tr>
            <tr><td colspan="3" class="amount">VAT ({{ $quotation->vat_rate }}%)</td><td class="amount">{{ $quotation->currency }} {{ $quotation->tax }}</td></tr>
            <tr class="total"><td colspan="3" class="amount">Total</td><td class="amount">{{ $quotation->currency }} {{ $quotation->total }}</td></tr>
        </tbody>
    </table>
    <h2>Description</h2><div class="multiline">{{ $quotation->description }}</div>
    <h2>Included plan modules and features</h2>
    @if ($quotation->plan_modules === null || $quotation->plan_features === null)
        <p class="muted">Plan details were not captured for this quotation.</p>
    @elseif (!count($quotation->plan_modules) && !count($quotation->plan_features))
        <p class="muted">No included modules or features were listed for this plan.</p>
    @else
        @foreach ($quotation->plan_modules as $module)
            <p><strong>{{ $module['name'] }}@if ($module['limit'] !== null && $module['limit'] !== ''): {{ $module['limit'] }}@endif</strong>
                @if ($module['description'])<br><span class="multiline">{{ $module['description'] }}</span>@endif
            </p>
            @if (count($module['features']))
                <ul>
                    @foreach ($module['features'] as $feature)
                        <li><strong>{{ $feature['name'] }}@if ($feature['limit'] !== null && $feature['limit'] !== ''): {{ $feature['limit'] }}@endif</strong>
                            @if ($feature['description']) - {{ $feature['description'] }}@endif
                        </li>
                    @endforeach
                </ul>
            @endif
        @endforeach
        @if (count($quotation->plan_features))
            <ul>
                @foreach ($quotation->plan_features as $feature)
                    <li><strong>{{ $feature['name'] }}@if ($feature['limit'] !== null && $feature['limit'] !== ''): {{ $feature['limit'] }}@endif</strong>
                        @if ($feature['description']) - {{ $feature['description'] }}@endif
                    </li>
                @endforeach
            </ul>
        @endif
    @endif
    @if ($quotation->terms)<h2>Terms</h2><div class="multiline">{{ $quotation->terms }}</div>@endif
    <p class="muted">This quotation is not an invoice. A later invoice is subject to the catalogue pricing and subscription rules in effect when it is created.</p>
</body>
</html>
