<script setup>
import SeoHead from '@/Components/Public/SeoHead.vue';
import CodeBlock from '@/Components/Public/CodeBlock.vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';

const laravelInstall = `composer require kernelbridge/licensing-client
php artisan vendor:publish --tag=kernelbridge-licensing-config
php artisan vendor:publish --tag=kernelbridge-licensing-migrations
php artisan migrate`;
const laravelEnv = `KERNELBRIDGE_API_URL=https://kernelbridge.com/api/v1
KERNELBRIDGE_PRODUCT_CODE=ALIGNEX
KERNELBRIDGE_API_TOKEN=replace-with-product-machine-token
KERNELBRIDGE_VERIFICATION_INTERVAL=15
KERNELBRIDGE_OFFLINE_GRACE_HOURS=72
KERNELBRIDGE_CACHE_SIGNING_KEY=replace-with-random-secret`;
const laravelController = `public function store(Request $request, LicenseActivationService $licenses): RedirectResponse
{
    $data = $request->validate([
        'license_key' => ['required', 'string', 'max:255'],
    ]);

    $licenses->activate($data['license_key'], config('app.name'));

    return redirect()->route('dashboard')->with('success', 'License activated.');
}`;
const laravelRoutes = `Route::post('/license/activate', [LicenseController::class, 'store'])
    ->middleware('throttle:5,1');

Route::middleware('kernelbridge.subscription.active')->group(function () {
    Route::get('/dashboard', DashboardController::class);
});

Route::middleware('kernelbridge.feature:finance')->group(function () {
    Route::resource('invoices', InvoiceController::class);
});`;
const electronInstall = `npm install @kernelbridge/licensing-client better-sqlite3`;
const electronMain = `const options = {
  apiUrl: process.env.KERNELBRIDGE_API_URL,
  productCode: 'ALIGNEX',
  tokenProvider: installationCredentials,
  verificationIntervalMs: 15 * 60_000,
  offlineGraceMs: 72 * 60 * 60_000,
};

const store = new SqliteLicenseStore(new Database(licenseDatabasePath));
const vault = new ElectronSafeStorageVault(safeStorage);
const cache = new LicenseCacheService(store, vault, options);
const api = new KernelBridgeLicenseClient(options);

export const activation = new LicenseActivationService(api, cache, options);
export const verification = new LicenseVerificationService(api, cache, options);
export const license = new LicenseGuard(cache);`;
const electronIpc = `ipcMain.handle('license:activate', async (_event, rawKey) => {
  const licenseKey = String(rawKey).trim();
  if (!/^KBT-[A-Z0-9-]+$/i.test(licenseKey)) {
    return { success: false, message: 'Enter a valid license key.' };
  }

  try {
    const state = await activation.activate(licenseKey, 'Customer desktop');
    return { success: true, status: state.status, expiresAt: state.expiresAt };
  } catch (error) {
    return { success: false, message: safeLicenseMessage(error) };
  }
});`;
const preload = `contextBridge.exposeInMainWorld('kernelBridge', {
  activateLicense: (licenseKey) => ipcRenderer.invoke('license:activate', licenseKey),
  subscriptionActive: () => ipcRenderer.invoke('license:active'),
  hasFeature: (key) => ipcRenderer.invoke('license:feature', key),
});`;
const vueForm = `<script setup lang="ts">
import { ref } from 'vue';

const licenseKey = ref('');
const error = ref('');
const activating = ref(false);

async function activate() {
  error.value = '';
  activating.value = true;
  const result = await window.kernelBridge.activateLicense(licenseKey.value);
  activating.value = false;
  if (!result.success) return error.value = result.message;
  window.location.assign('/');
}
<\/script>

<template>
  <form @submit.prevent="activate">
    <label for="license-key">License key</label>
    <input id="license-key" v-model.trim="licenseKey" required
      autocomplete="off" spellcheck="false"
      placeholder="KBT-XXXXXXXX-XXXXXXXX-XXXXXXXX" />
    <p v-if="error" role="alert">{{ error }}</p>
    <button :disabled="activating || !licenseKey">
      {{ activating ? 'Activating…' : 'Activate license' }}
    </button>
  </form>
</template>`;
const apiEndpoints = ['POST /api/v1/licenses/activate', 'POST /api/v1/licenses/verify', 'POST /api/v1/licenses/deactivate', 'GET /api/v1/subscriptions/status', 'GET /api/v1/entitlements'];
</script>

<template>
  <SeoHead title="Licensing Integration Documentation | KernelBridge" description="Install KernelBridge licensing in Laravel or Electron applications and build a secure license activation interface." canonical="https://kernelbridge.com/docs/licensing" />
  <PublicLayout>
    <section class="page-hero"><div class="page-shell"><p class="section-label !text-teal-300">Developer documentation</p><h1 class="page-title">Integrate KernelBridge licensing.</h1><p class="page-intro">Choose the client for your product, connect the versioned API, and provide customers with a secure activation experience.</p></div></section>
    <div class="page-shell grid gap-10 py-16 lg:grid-cols-[240px_minmax(0,1fr)] lg:py-20">
      <aside class="lg:sticky lg:top-28 lg:self-start"><nav aria-label="Licensing documentation" class="rounded-2xl border border-slate-200 bg-white p-4 text-sm shadow-sm"><p class="px-3 pb-2 text-xs font-bold uppercase tracking-wider text-slate-400">On this page</p><a v-for="item in [['overview','Choose a package'],['laravel','Laravel installation'],['electron','Electron installation'],['interface','Activation interface'],['security','Security checklist'],['api','API contract']]" :key="item[0]" :href="`#${item[0]}`" class="block rounded-lg px-3 py-2 font-semibold text-slate-600 hover:bg-cyan-50 hover:text-cyan-800">{{ item[1] }}</a></nav></aside>
      <article class="min-w-0 space-y-16 text-slate-700">
        <section id="overview" class="scroll-mt-28"><p class="section-label">Choose a package</p><h2 class="section-title">One central API, two product clients.</h2><p class="mt-5 max-w-3xl leading-8">These packages run inside KernelBridge product applications. They do not replace the central licensing platform at kernelbridge.com.</p><div class="mt-8 grid gap-5 md:grid-cols-2"><div class="rounded-2xl border border-slate-200 bg-white p-6"><p class="text-xs font-bold uppercase text-cyan-700">Laravel + MySQL/SQLite</p><h3 class="mt-2 text-xl font-bold text-[#0B3D6E]">kernelbridge/licensing-client</h3><p class="mt-3 leading-7">Use for Laravel products. Includes Eloquent state, migrations, middleware aliases, queues and scheduled verification.</p></div><div class="rounded-2xl border border-slate-200 bg-white p-6"><p class="text-xs font-bold uppercase text-cyan-700">Electron + SQLite</p><h3 class="mt-2 text-xl font-bold text-[#0B3D6E]">@kernelbridge/licensing-client</h3><p class="mt-3 leading-7">Use in Electron's main process. Includes encrypted SQLite state, Electron safeStorage, feature guards and periodic verification.</p></div></div></section>

        <section id="laravel" class="scroll-mt-28"><p class="section-label">Laravel products</p><h2 class="section-title">Install the PHP client.</h2><p class="mt-5 leading-8">Add the private Composer repository, require the package, publish its configuration and migration, then migrate the product's own database.</p><CodeBlock title="Terminal" :code="laravelInstall"/><h3 class="mt-9 text-xl font-bold text-[#0B3D6E]">Environment</h3><p class="mt-3 leading-7">Every product and environment receives independent credentials. Never commit these values.</p><CodeBlock title=".env" :code="laravelEnv"/><h3 class="mt-9 text-xl font-bold text-[#0B3D6E]">Activation controller</h3><CodeBlock title="LicenseController.php" :code="laravelController"/><h3 class="mt-9 text-xl font-bold text-[#0B3D6E]">Route protection</h3><CodeBlock title="routes/web.php" :code="laravelRoutes"/></section>

        <section id="electron" class="scroll-mt-28"><p class="section-label">Electron products</p><h2 class="section-title">Install the TypeScript client.</h2><CodeBlock title="Terminal" :code="electronInstall"/><p class="mt-5 leading-8">Create all licensing services in the Electron main process. SQLite receives one encrypted envelope; the renderer receives only decisions.</p><CodeBlock title="main/licensing.ts" :code="electronMain"/><div class="mt-6 rounded-2xl border border-amber-200 bg-amber-50 p-5 text-amber-950"><strong>Desktop credential warning:</strong> never embed a permanent central product token in a public Electron binary. Use a short-lived, revocable installation credential supplied through <code>tokenProvider</code>.</div></section>

        <section id="interface" class="scroll-mt-28"><p class="section-label">Customer experience</p><h2 class="section-title">Create the activation interface.</h2><ol class="mt-6 grid gap-4 sm:grid-cols-2"><li v-for="(step,index) in ['Customer enters the key','Renderer sends it over isolated IPC','Main process validates and activates','Encrypted state is stored locally','Entitlements unlock approved features','Background verification keeps state current']" :key="step" class="rounded-xl border border-slate-200 bg-white p-4"><span class="text-xs font-bold text-cyan-700">{{ String(index+1).padStart(2,'0') }}</span><p class="mt-1 font-semibold">{{ step }}</p></li></ol><h3 class="mt-9 text-xl font-bold text-[#0B3D6E]">Main-process IPC handler</h3><CodeBlock title="main/licensing-ipc.ts" :code="electronIpc"/><h3 class="mt-9 text-xl font-bold text-[#0B3D6E]">Preload bridge</h3><CodeBlock title="preload.ts" :code="preload"/><h3 class="mt-9 text-xl font-bold text-[#0B3D6E]">Vue activation form</h3><CodeBlock title="LicenseActivation.vue" :code="vueForm"/></section>

        <section id="security" class="scroll-mt-28"><p class="section-label">Security</p><h2 class="section-title">Production checklist.</h2><ul class="mt-6 space-y-3"><li v-for="item in ['Keep API calls and license keys out of Electron renderers.','Use context isolation and allow-list IPC feature keys.','Encrypt desktop state with Electron safeStorage before SQLite.','Use independent credentials for every product and environment.','Rate-limit activation and never log license keys or bearer tokens.','Verify feature and limit decisions inside sensitive backend/main-process operations.','Fail closed after explicit revocation, expiry or offline grace expiry.','Run verification at startup and periodically—not on every page request.']" :key="item" class="flex gap-3 rounded-xl bg-white p-4 ring-1 ring-slate-200"><span aria-hidden="true" class="font-bold text-cyan-600">✓</span><span>{{ item }}</span></li></ul></section>

        <section id="api" class="scroll-mt-28"><p class="section-label">Protocol</p><h2 class="section-title">Versioned API contract.</h2><p class="mt-5 leading-8">Both clients use the same public UUID-based API. Successful responses use <code>success</code>, <code>data</code> and request metadata; errors use the common error envelope.</p><div class="mt-6 grid gap-3 sm:grid-cols-2"><code v-for="endpoint in apiEndpoints" :key="endpoint" class="rounded-xl bg-[#071426] px-4 py-3 text-sm text-cyan-100">{{ endpoint }}</code></div><p class="mt-6 leading-8">Contact KernelBridge before shipping a new product integration so credentials, scopes, rate limits, offline policy and activation limits can be reviewed.</p></section>
      </article>
    </div>
  </PublicLayout>
</template>
