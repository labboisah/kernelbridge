<script setup>
import AdminNavigation from '@/Components/Admin/AdminNavigation.vue';
import InputError from '@/Components/InputError.vue';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';

const props = defineProps({
    products: Array,
    clients: Array,
    presets: Object,
    filters: Object,
    auditLogs: Array,
    toolOptions: Array,
    credentialOptions: Array,
    apiUrl: String,
    issuedToken: String,
    issuedCredential: Object,
});

const firstProduct = props.products[0]?.uuid || '';
const presetKeys = Object.keys(props.presets || {});
const clientForm = useForm({ product_uuid: firstProduct, preset: 'support_read', name: 'KernelBridge MCP operator', credential_name: 'primary', expires_at: '', rate_limit_per_minute: 120, allowed_ips: '' });
const rotateForms = reactive({});
const filters = reactive({ ...props.filters });
const tokenValue = computed(() => props.issuedToken || '<paste-issued-token>');
const envSnippet = computed(() => `KERNELBRIDGE_API_URL=${props.apiUrl}\nKERNELBRIDGE_API_TOKEN=${tokenValue.value}`);
const clientSnippet = computed(() => JSON.stringify({ mcpServers: { kernelbridge: { command: 'npx', args: ['@kernelbridge/licensing-mcp-server'], env: { KERNELBRIDGE_API_URL: props.apiUrl, KERNELBRIDGE_API_TOKEN: tokenValue.value } } } }, null, 2));
const submitClient = () => clientForm.post(route('admin.mcp.clients.store'), { preserveScroll: true });
const applyFilters = () => router.get(route('admin.mcp.index'), filters, { preserveState: true, replace: true });
const resetFilters = () => router.get(route('admin.mcp.index'));
const rotateForm = credential => {
    if (!rotateForms[credential.uuid]) rotateForms[credential.uuid] = useForm({ name: `${credential.name} rotated`, expires_at: '' });
    return rotateForms[credential.uuid];
};
const rotate = credential => rotateForm(credential).post(route('admin.mcp.credentials.rotate', credential.uuid), { preserveScroll: true });
const revoke = credential => {
    if (window.confirm(`Revoke ${credential.name}? Existing MCP sessions using this token will fail.`)) router.post(route('admin.mcp.credentials.revoke', credential.uuid), {}, { preserveScroll: true });
};
</script>

<template>
    <Head title="MCP Operators" />
    <PortalLayout area="Administration">
        <template #navigation><AdminNavigation active="mcp" /></template>

        <div v-if="$page.props.flash.success" class="success-message mb-4">{{ $page.props.flash.success }}</div>

        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="text-sm font-semibold uppercase tracking-wider text-cyan-700">Assistant operations</p>
                <h1 class="mt-2 text-3xl font-bold">MCP Operators</h1>
                <p class="mt-2 max-w-3xl text-slate-600">Create scoped assistant tokens, rotate credentials, and review KernelBridge MCP tool activity.</p>
            </div>
            <div class="rounded-xl bg-[#071927] px-5 py-4 text-white shadow-sm">
                <p class="text-xs uppercase tracking-[.16em] text-cyan-200">Endpoint</p>
                <p class="mt-1 font-mono text-sm">{{ apiUrl }}</p>
            </div>
        </div>

        <section v-if="issuedToken" class="mt-6 rounded-2xl bg-[#071927] p-6 text-white shadow-sm">
            <p class="text-sm font-semibold uppercase tracking-wider text-cyan-200">One-time token</p>
            <p class="mt-2 text-sm text-slate-300">This value will disappear after refresh.</p>
            <pre class="mt-4 overflow-auto rounded-xl bg-black/30 p-4 text-xs text-cyan-100">{{ issuedToken }}</pre>
            <div class="mt-5 grid gap-4 lg:grid-cols-2">
                <div>
                    <h2 class="font-bold">Environment</h2>
                    <pre class="mt-2 overflow-auto rounded-xl bg-black/30 p-4 text-xs text-cyan-100">{{ envSnippet }}</pre>
                </div>
                <div>
                    <h2 class="font-bold">MCP client snippet</h2>
                    <pre class="mt-2 overflow-auto rounded-xl bg-black/30 p-4 text-xs text-cyan-100">{{ clientSnippet }}</pre>
                </div>
            </div>
        </section>

        <div class="mt-7 grid gap-6 xl:grid-cols-[420px_1fr]">
            <section class="rounded-2xl bg-white p-6 shadow-sm">
                <h2 class="text-xl font-bold">Issue MCP token</h2>
                <form class="mt-5 space-y-4" @submit.prevent="submitClient">
                    <label class="block text-sm font-semibold">Product<select v-model="clientForm.product_uuid" class="mt-1 w-full rounded-xl border-slate-300"><option v-for="product in products" :key="product.uuid" :value="product.uuid">{{ product.name }} ({{ product.code }})</option></select><InputError :message="clientForm.errors.product_uuid" /></label>
                    <label class="block text-sm font-semibold">Preset<select v-model="clientForm.preset" class="mt-1 w-full rounded-xl border-slate-300"><option v-for="key in presetKeys" :key="key" :value="key">{{ presets[key].label }}</option></select><InputError :message="clientForm.errors.preset" /></label>
                    <p class="rounded-xl bg-cyan-50 p-3 text-sm text-cyan-900">{{ presets[clientForm.preset]?.description }}</p>
                    <label class="block text-sm font-semibold">Client name<input v-model="clientForm.name" class="mt-1 w-full rounded-xl border-slate-300" /><InputError :message="clientForm.errors.name" /></label>
                    <label class="block text-sm font-semibold">Credential name<input v-model="clientForm.credential_name" class="mt-1 w-full rounded-xl border-slate-300" /><InputError :message="clientForm.errors.credential_name" /></label>
                    <div class="grid gap-4 sm:grid-cols-2"><label class="block text-sm font-semibold">Expires at<input v-model="clientForm.expires_at" type="date" class="mt-1 w-full rounded-xl border-slate-300" /><InputError :message="clientForm.errors.expires_at" /></label><label class="block text-sm font-semibold">Rate / minute<input v-model="clientForm.rate_limit_per_minute" type="number" min="1" max="600" class="mt-1 w-full rounded-xl border-slate-300" /><InputError :message="clientForm.errors.rate_limit_per_minute" /></label></div>
                    <label class="block text-sm font-semibold">Allowed IPs<textarea v-model="clientForm.allowed_ips" rows="3" class="mt-1 w-full rounded-xl border-slate-300" placeholder="Optional, comma or newline separated"></textarea><InputError :message="clientForm.errors.allowed_ips" /></label>
                    <button class="form-button w-full" :disabled="clientForm.processing">Issue token</button>
                </form>
            </section>

            <section class="space-y-5">
                <article v-for="client in clients" :key="client.uuid" class="rounded-2xl bg-white p-6 shadow-sm">
                    <div class="flex flex-wrap justify-between gap-3">
                        <div><h2 class="text-xl font-bold">{{ client.name }}</h2><p class="text-sm text-slate-500">{{ client.product?.name }} · {{ client.rate_limit_per_minute }}/minute</p></div>
                        <div class="flex flex-wrap gap-2"><span v-for="scope in client.scopes" :key="scope" class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-700">{{ scope }}</span></div>
                    </div>
                    <div class="mt-4 overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr class="border-b text-xs uppercase tracking-wider text-slate-500"><th class="py-2">Credential</th><th>Expires</th><th>Status</th><th class="text-right">Actions</th></tr></thead><tbody><tr v-for="credential in client.credentials" :key="credential.uuid" class="border-b last:border-0"><td class="py-3"><strong>{{ credential.name }}</strong><p class="font-mono text-xs text-slate-400">{{ credential.uuid }}</p></td><td>{{ credential.expires_at || 'Never' }}</td><td><span :class="credential.is_active ? 'text-emerald-700' : 'text-rose-700'" class="font-bold">{{ credential.is_active ? 'Active' : 'Inactive' }}</span></td><td class="py-3 text-right"><div class="flex flex-wrap justify-end gap-2"><input v-model="rotateForm(credential).name" class="w-44 rounded-lg border-slate-300 text-sm" /><input v-model="rotateForm(credential).expires_at" type="date" class="w-36 rounded-lg border-slate-300 text-sm" /><button class="rounded-lg bg-[#0B3D6E] px-3 py-2 text-xs font-bold text-white" @click="rotate(credential)">Rotate</button><button class="rounded-lg border border-rose-200 px-3 py-2 text-xs font-bold text-rose-700" @click="revoke(credential)">Revoke</button></div></td></tr></tbody></table></div>
                </article>
                <p v-if="!clients.length" class="rounded-2xl bg-white p-6 text-slate-500 shadow-sm">No MCP API clients have been issued yet.</p>
            </section>
        </div>

        <section class="mt-8 rounded-2xl bg-white p-6 shadow-sm">
            <div class="flex flex-wrap items-end justify-between gap-3"><div><h2 class="text-xl font-bold">MCP audit logs</h2><p class="text-sm text-slate-500">Latest 100 tool calls matching the selected filters.</p></div><button class="rounded-xl border px-4 py-2 text-sm font-bold" @click="resetFilters">Reset</button></div>
            <div class="mt-5 grid gap-3 md:grid-cols-5"><select v-model="filters.tool" class="rounded-xl border-slate-300" @change="applyFilters"><option value="">All tools</option><option v-for="tool in toolOptions" :key="tool" :value="tool">{{ tool }}</option></select><select v-model="filters.mutation" class="rounded-xl border-slate-300" @change="applyFilters"><option value="">All calls</option><option value="false">Read</option><option value="true">Mutation</option></select><select v-model="filters.credential" class="rounded-xl border-slate-300" @change="applyFilters"><option value="">All credentials</option><option v-for="credential in credentialOptions" :key="credential.uuid" :value="credential.uuid">{{ credential.name }}</option></select><input v-model="filters.date_from" type="date" class="rounded-xl border-slate-300" @change="applyFilters" /><input v-model="filters.date_to" type="date" class="rounded-xl border-slate-300" @change="applyFilters" /></div>
            <div class="mt-5 overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr class="border-b text-xs uppercase tracking-wider text-slate-500"><th class="py-2">Tool</th><th>Client</th><th>Target</th><th>Type</th><th>When</th></tr></thead><tbody><tr v-for="log in auditLogs" :key="log.request_id" class="border-b last:border-0"><td class="py-3"><strong>{{ log.tool_name }}</strong><p class="font-mono text-xs text-slate-400">{{ log.request_id }}</p></td><td>{{ log.client_name || 'Unknown' }}<p class="text-xs text-slate-500">{{ log.credential_name }}</p></td><td>{{ log.target_type }}<p class="font-mono text-xs text-slate-400">{{ log.target_uuid }}</p></td><td><span :class="log.mutation ? 'bg-rose-50 text-rose-700' : 'bg-emerald-50 text-emerald-700'" class="rounded-full px-3 py-1 text-xs font-bold">{{ log.mutation ? 'Mutation' : 'Read' }}</span></td><td>{{ log.created_at }}</td></tr></tbody></table></div>
            <p v-if="!auditLogs.length" class="mt-5 text-sm text-slate-500">No MCP audit records match these filters.</p>
        </section>
    </PortalLayout>
</template>
