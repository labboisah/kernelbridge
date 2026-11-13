<script setup>
import AdminNavigation from '@/Components/Admin/AdminNavigation.vue';
import InputError from '@/Components/InputError.vue';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    license: { type: Object, required: true },
    activationCodes: { type: Array, required: true },
    installations: { type: Array, required: true },
    platformLinks: { type: Array, required: true },
});
const issueForm = useForm({ platform_account_link_uuid: '', installation_uuid: '', device_name: '' });
const needsPlatformLink = computed(() => props.license.product.mode === 'platform_account');
const supportsBoundInstallations = computed(() => ['platform_account', 'hybrid'].includes(props.license.product.mode));
const supportsCodeIssuance = computed(() => ['direct', 'platform_account', 'hybrid'].includes(props.license.product.mode));
const isBoundCode = computed(() => Boolean(issueForm.platform_account_link_uuid));
const dateTime = value => value ? new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)) : '—';
const issueCode = () => issueForm.post(route('admin.licenses.codes.issue', props.license.uuid), { preserveScroll: true, onSuccess: () => issueForm.reset() });
const revokeCode = code => {
    if (window.confirm(`Revoke this unused ${code.kind} code? The code will stop working immediately.`)) {
        router.post(route('admin.licenses.codes.revoke', { license: props.license.uuid, activationCode: code.uuid }), {}, { preserveScroll: true });
    }
};
const revokeInstallation = installation => {
    if (window.confirm(`Revoke installation ${installation.installation_uuid}? It will lose access immediately and release its seat.`)) {
        router.post(route('admin.licenses.installations.revoke', { license: props.license.uuid, installation: installation.uuid }), {}, { preserveScroll: true });
    }
};
const copyCode = async code => {
    try {
        await navigator.clipboard.writeText(code);
    } catch {
        window.prompt('Copy the one-time code:', code);
    }
};
</script>

<template>
    <Head :title="`License ${license.product.code}`" />
    <PortalLayout area="Administration">
        <template #navigation><AdminNavigation active="licenses" /></template>

        <div v-if="$page.props.flash.success" class="success-message mb-5">{{ $page.props.flash.success }}</div>
        <div v-if="$page.props.flash.error" role="alert" class="mb-5 rounded-xl border border-red-200 bg-red-50 p-4 text-red-800">{{ $page.props.flash.error }}</div>

        <div class="mb-6">
            <Link :href="route('admin.licenses.index')" class="text-sm font-bold text-cyan-700">← Licensing Codes</Link>
            <div class="mt-3 flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-sm font-semibold uppercase tracking-wider text-cyan-700">{{ license.product.code }} · {{ license.product.mode.replaceAll('_', ' ') }}</p>
                    <h1 class="mt-1 text-3xl font-bold text-slate-900">{{ license.product.name }} license</h1>
                    <p class="mt-2 break-all font-mono text-sm text-slate-500">{{ license.uuid }}</p>
                </div>
                <span class="rounded-full bg-slate-100 px-4 py-2 text-sm font-bold uppercase">{{ license.status }}</span>
            </div>
        </div>

        <section class="grid gap-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm md:grid-cols-2 xl:grid-cols-4">
            <div><p class="text-xs font-bold uppercase tracking-wide text-slate-400">Customer</p><p class="mt-1 font-semibold">{{ license.customer.name }}</p><p class="text-sm text-slate-500">{{ license.customer.email || 'No email' }}</p></div>
            <div><p class="text-xs font-bold uppercase tracking-wide text-slate-400">Plan</p><p class="mt-1 font-semibold">{{ license.subscription.plan || 'No plan' }}</p><p class="text-sm text-slate-500">{{ license.subscription.number || license.subscription.uuid }}</p></div>
            <div><p class="text-xs font-bold uppercase tracking-wide text-slate-400">Subscription</p><p class="mt-1 font-semibold capitalize">{{ license.subscription.status }}</p><p class="text-sm text-slate-500">License expires {{ dateTime(license.expires_at) }}</p></div>
            <div><p class="text-xs font-bold uppercase tracking-wide text-slate-400">Installation limit</p><p class="mt-1 font-semibold">{{ license.max_activations }}</p><p class="text-sm text-slate-500">Shared activation/install seats</p></div>
        </section>

        <section v-if="$page.props.flash.activation_code?.license_uuid === license.uuid" class="mt-6 rounded-2xl border border-emerald-300 bg-emerald-50 p-5">
            <p class="text-sm font-semibold uppercase tracking-wider text-emerald-800">One-time {{ $page.props.flash.activation_code.kind }} code</p>
            <p class="mt-1 text-sm text-emerald-800">Copy and securely provide this code now. KernelBridge will not show it again. It expires {{ dateTime($page.props.flash.activation_code.expires_at) }}.</p>
            <p v-if="$page.props.flash.activation_code.installation_uuid" class="mt-2 text-sm text-emerald-800">Bound installation: <code>{{ $page.props.flash.activation_code.installation_uuid }}</code></p>
            <div class="mt-4 flex flex-wrap items-center gap-3">
                <code class="break-all rounded-lg bg-white px-4 py-3 font-bold text-slate-900 ring-1 ring-emerald-300">{{ $page.props.flash.activation_code.code }}</code>
                <button type="button" class="rounded-lg bg-emerald-700 px-4 py-3 text-sm font-bold text-white" @click="copyCode($page.props.flash.activation_code.code)">Copy code</button>
            </div>
        </section>

        <section class="mt-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="text-xl font-bold text-slate-900">Issue a code</h2>
            <p class="mt-1 text-sm text-slate-600">Codes are one-time use, expire after 24 hours, and reserve an installation seat.</p>
            <p v-if="!supportsCodeIssuance" class="mt-4 rounded-xl bg-amber-50 p-4 text-sm text-amber-900">This license uses a licensing mode that does not issue unified activation codes.</p>
            <form v-else class="mt-5 grid gap-4 md:grid-cols-2" @submit.prevent="issueCode">
                <label v-if="supportsBoundInstallations" class="block">
                    <span class="mb-1 block text-sm font-semibold">Approved platform account link{{ needsPlatformLink ? '' : ' (optional)' }}</span>
                    <select v-model="issueForm.platform_account_link_uuid" class="w-full" :required="needsPlatformLink">
                        <option value="">{{ needsPlatformLink ? 'Select an approved platform account link' : 'Direct activation code' }}</option>
                        <option v-for="link in platformLinks" :key="link.uuid" :value="link.uuid">{{ link.business_uuid }} · approved {{ dateTime(link.approved_at) }}</option>
                    </select>
                    <InputError :message="issueForm.errors.platform_account_link_uuid" />
                    <p v-if="needsPlatformLink && !platformLinks.length" class="mt-1 text-sm text-amber-700">Approve a platform account link before issuing an installation code.</p>
                </label>
                <label v-if="isBoundCode || needsPlatformLink" class="block">
                    <span class="mb-1 block text-sm font-semibold">Installation UUID</span>
                    <input v-model="issueForm.installation_uuid" class="w-full" placeholder="UUID from the product installation" required>
                    <InputError :message="issueForm.errors.installation_uuid" />
                </label>
                <label v-if="isBoundCode || needsPlatformLink" class="block">
                    <span class="mb-1 block text-sm font-semibold">Device name (optional)</span>
                    <input v-model="issueForm.device_name" class="w-full" maxlength="255" placeholder="e.g. Main clinic server">
                    <InputError :message="issueForm.errors.device_name" />
                </label>
                <div class="md:col-span-2">
                    <p v-if="isBoundCode || needsPlatformLink" class="mb-3 text-sm text-slate-600">This creates an installation code bound to the selected business and installation UUID.</p>
                    <p v-else class="mb-3 text-sm text-slate-600">This creates a general activation code for a product configured for direct or hybrid licensing.</p>
                    <button class="form-button" :disabled="issueForm.processing || (needsPlatformLink && !platformLinks.length)">Issue one-time code</button>
                </div>
            </form>
        </section>

        <section class="mt-6 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 px-5 py-4"><h2 class="text-xl font-bold">Activation and installation codes</h2></div>
            <div v-for="code in activationCodes" :key="code.uuid" class="grid gap-3 border-b border-slate-100 p-5 last:border-0 lg:grid-cols-[1fr_1.2fr_.8fr_auto] lg:items-center">
                <div><p class="font-bold capitalize">{{ code.kind }} code <span class="ml-2 rounded-full bg-slate-100 px-2 py-1 text-xs uppercase">{{ code.status }}</span></p><p class="mt-1 break-all font-mono text-xs text-slate-500">{{ code.uuid }}</p></div>
                <div class="text-sm text-slate-600"><p v-if="code.bound_installation_uuid">Bound to {{ code.bound_installation_uuid }}</p><p v-if="code.device_name">{{ code.device_name }}</p><p v-if="code.redeemed_installation_uuid">Redeemed by {{ code.redeemed_installation_uuid }}</p></div>
                <div class="text-sm text-slate-600"><p>Issued {{ dateTime(code.issued_at) }}</p><p>Expires {{ dateTime(code.expires_at) }}</p></div>
                <button v-if="code.can_revoke" type="button" class="rounded-lg border border-red-200 px-4 py-2 text-sm font-bold text-red-700" @click="revokeCode(code)">Revoke unused code</button>
            </div>
            <p v-if="!activationCodes.length" class="p-8 text-center text-slate-500">No unified activation codes have been issued for this license.</p>
        </section>

        <section class="mt-6 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 px-5 py-4"><h2 class="text-xl font-bold">Installations</h2><p class="mt-1 text-sm text-slate-600">Revoking an active installation invalidates its credential and releases the seat immediately.</p></div>
            <div v-for="installation in installations" :key="installation.uuid" class="grid gap-3 border-b border-slate-100 p-5 last:border-0 lg:grid-cols-[1fr_1.2fr_.8fr_auto] lg:items-center">
                <div><p class="font-bold">{{ installation.device_name || 'Unnamed installation' }} <span class="ml-2 rounded-full bg-slate-100 px-2 py-1 text-xs uppercase">{{ installation.status }}</span></p><p class="mt-1 break-all font-mono text-xs text-slate-500">{{ installation.installation_uuid }}</p></div>
                <div class="text-sm text-slate-600"><p>Business: {{ installation.business_uuid || 'Not linked' }}</p><p>Runtime: {{ installation.runtime_type }}</p></div>
                <div class="text-sm text-slate-600"><p>Activated {{ dateTime(installation.activated_at) }}</p><p>Last verified {{ dateTime(installation.last_verified_at) }}</p></div>
                <button v-if="installation.can_revoke" type="button" class="rounded-lg border border-red-200 px-4 py-2 text-sm font-bold text-red-700" @click="revokeInstallation(installation)">Revoke installation</button>
            </div>
            <p v-if="!installations.length" class="p-8 text-center text-slate-500">No installations have been activated for this license.</p>
        </section>
    </PortalLayout>
</template>
