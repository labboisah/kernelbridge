<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>KernelBridge license activation</title>
    <link rel="stylesheet" href="{{ asset(config('kernelbridge-licensing.ui.css_path', 'vendor/kernelbridge-licensing/activation.css')) }}">
</head>
<body>
    <main class="kb-page">
        <aside class="kb-brand" aria-label="KernelBridge licensing">
            <a class="kb-logo-line" href="{{ url('/') }}" aria-label="KernelBridge home">
                <img class="kb-logo" src="{{ asset(config('kernelbridge-licensing.ui.logo_path', 'vendor/kernelbridge-licensing/kernelbridge-logo.png')) }}" alt="KernelBridge Technologies">
                <span class="kb-wordmark">
                    KernelBridge
                    <small>Technologies LTD</small>
                </span>
            </a>

            <div class="kb-brand-copy">
                <p class="kb-eyebrow">Product licensing</p>
                <h1>Secure activation for this installation.</h1>
                <p>KernelBridge verifies your subscription, protects local entitlements, and keeps this product available only on approved devices.</p>
                <ul class="kb-security-list">
                    <li><span class="kb-check">OK</span><span>Signed local license state</span></li>
                    <li><span class="kb-check">OK</span><span>Device activation limit enforcement</span></li>
                    <li><span class="kb-check">OK</span><span>Configuration tamper detection</span></li>
                </ul>
            </div>

            <div class="kb-brand-foot">KernelBridge licensing client</div>
        </aside>

        <div class="kb-panel-wrap">
            <section class="kb-panel" aria-labelledby="license-title">
                <header class="kb-panel-header">
                    <p class="kb-eyebrow">Activation center</p>
                    <h2 id="license-title">License status</h2>
                    <p>Activate, verify, or deactivate this product installation.</p>
                </header>

                @if (session('kernelbridge_license_status'))
                    <div class="kb-alert kb-alert-success">{{ session('kernelbridge_license_status') }}</div>
                @endif

                @if (! $hasUsableLicense)
                    <div class="kb-alert kb-alert-warning">{{ $activationMessage }}</div>
                @endif

                @if ($errors->any())
                    <div class="kb-alert kb-alert-error">{{ $errors->first() }}</div>
                @endif

                <div class="kb-meta">
                    <div><span>Product</span><strong>{{ $productCode ?: 'Not configured' }}</strong></div>
                    <div><span>Status</span><strong>{{ $state->status }}</strong></div>
                    <div><span>Reason</span><strong>{{ str_replace('_', ' ', $activationReason) }}</strong></div>
                    <div><span>License</span><strong>{{ $hasUsableLicense ? 'Active' : 'Not active' }}</strong></div>
                    @if ($state->last_successful_verification_at)
                        <div><span>Verified</span><strong>{{ $state->last_successful_verification_at->diffForHumans() }}</strong></div>
                    @endif
                </div>

                <form class="kb-form" method="post" action="{{ route(config('kernelbridge-licensing.routes.name', 'kernelbridge.license.').'activate') }}">
                    @csrf
                    <label class="kb-field" for="license_key">
                        <span>License key</span>
                        <input id="license_key" name="license_key" value="{{ old('license_key') }}" placeholder="KBT-..." required autocomplete="off" spellcheck="false">
                    </label>

                    <label class="kb-field" for="device_name">
                        <span>Device name</span>
                        <input id="device_name" name="device_name" value="{{ old('device_name', config('app.name')) }}" autocomplete="organization">
                    </label>

                    <div class="kb-actions">
                        <button class="kb-button" type="submit">Activate license</button>
                    </div>
                </form>

                <div class="kb-secondary-actions">
                    <form method="post" action="{{ route(config('kernelbridge-licensing.routes.name', 'kernelbridge.license.').'verify') }}">
                        @csrf
                        <button class="kb-button kb-button-secondary" type="submit">Verify now</button>
                    </form>

                    <form method="post" action="{{ route(config('kernelbridge-licensing.routes.name', 'kernelbridge.license.').'deactivate') }}">
                        @csrf
                        @method('delete')
                        <button class="kb-button kb-button-danger" type="submit">Deactivate</button>
                    </form>
                </div>
            </section>
        </div>
    </main>
</body>
</html>

