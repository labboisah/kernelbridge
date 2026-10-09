<script setup>
import { computed } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import PackageOption from '@/Components/Customer/PackageOption.vue';
import PackageManager from '@/Components/Customer/PackageManager.vue';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import CustomerNavigation from '@/Components/Customer/CustomerNavigation.vue';

const props = defineProps({ credits: { type: Array, default: () => [] }, creditHistory: { type: Array, default: () => [] }, licenses: { type: Array, default: () => [] }, packages: { type: Array, default: () => [] }, orders: { type: Array, default: () => [] }, edunexaPlans: { type: Array, default: () => [] }, edunexaOrder: { type: Object, default: null }, vatRate: { type: Number, default: 0 } });
const firstPurchasableEdunexaPlan = props.edunexaPlans.find(item => item.slug === 'basic' && item.purchasable) || props.edunexaPlans.find(item => item.purchasable);
const form = useForm({ packages: [], use_credit: true });
const edunexaForm = useForm({ plan_uuid: firstPurchasableEdunexaPlan?.uuid || '', use_credit: true });
const selected = computed(() => props.packages.filter(item => form.packages.includes(item.uuid)));
const primary = computed(() => props.packages.find(item => item.uuid === form.packages[0]));
const recurring = computed(() => selected.value.reduce((total, item) => total + Math.round(Number(item.price) * 100), 0) / 100);
const setup = computed(() => selected.value.reduce((total, item) => total + Math.round(Number(item.setup_fee) * 100), 0) / 100);
const modules = computed(() => [...new Map(selected.value.flatMap(item => item.modules).map(item => [item.key, item])).values()]);
const selectedEdunexaPlan = computed(() => props.edunexaPlans.find(item => item.uuid === edunexaForm.plan_uuid));
const edunexaAmount = computed(() => Number(selectedEdunexaPlan.value?.price || 0) + Number(selectedEdunexaPlan.value?.setup_fee || 0));
const edunexaVat = computed(() => edunexaAmount.value * Number(props.vatRate || 0) / 100);
const currentOrder = computed(() => props.orders.find(item => ['pending', 'trial', 'active', 'grace', 'suspended'].includes(item.status)));
const compatible = item => !primary.value || (item.currency === primary.value.currency && item.billing_cycle === primary.value.billing_cycle);
const available = item => Number(item.price) > 0 && ['monthly', 'quarterly', 'semiannual', 'annual'].includes(item.billing_cycle);
const money = (value, currency = 'NGN') => new Intl.NumberFormat('en-NG', { style: 'currency', currency }).format(Number(value));
const dateTime = value => value ? new Intl.DateTimeFormat('en-NG', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)) : 'Never';
const toggle = uuid => { form.packages = form.packages.includes(uuid) ? form.packages.filter(id => id !== uuid) : [...form.packages, uuid]; };
const purchase = () => router.get(route('portal.checkout.show'), { packages: form.packages });
const purchaseEdunexa = () => edunexaForm.post(route('portal.licenses.edunexa.purchase'));
const deactivate = activation => { if (confirm('Deactivate this installation? Device-bound licences remain assigned to this computer.')) router.post(route('portal.licenses.activations.deactivate', activation.uuid)); };
const copy = async key => { try { await navigator.clipboard.writeText(key); } catch { alert('Select and copy the activation key manually.'); } };
</script>

<template>
    <Head title="Licensing" />
    <PortalLayout area="Customer Portal">
        <template #navigation><CustomerNavigation active="licenses" /></template>
        <div class="mb-6"><h1 class="text-3xl font-bold">Licensing</h1><p class="mt-2 text-slate-600">Choose an EduNexa plan or MediFlow packages, pay your invoice, and activate your computer.</p></div>
        <p v-if="$page.props.flash.success" class="success-message mb-5">{{ $page.props.flash.success }}</p>
        <p v-if="$page.props.errors.activation" role="alert" class="mb-5 text-red-700">{{ $page.props.errors.activation }}</p>

        <section class="mb-8 rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
            <h2 class="text-xl font-bold">EduNexa plans</h2>
            <p class="mt-2 text-sm text-slate-600">Choose an annual plan. Each plan includes its listed modules and features. VAT: {{ vatRate }}%.</p>
            <div v-if="edunexaOrder" class="mt-4 rounded-xl bg-cyan-50 p-4 text-sm">You already have an EduNexa {{ edunexaOrder.status }} subscription.<Link v-if="edunexaOrder.invoice" :href="route('portal.invoices.show', edunexaOrder.invoice.uuid)" class="ml-2 font-bold text-cyan-800 underline">View invoice {{ edunexaOrder.invoice.number }}</Link></div>
            <form class="mt-5" @submit.prevent="purchaseEdunexa">
                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    <label v-for="plan in edunexaPlans" :key="plan.uuid" class="rounded-xl border p-5" :class="edunexaForm.plan_uuid === plan.uuid ? 'border-cyan-600 bg-cyan-50' : 'border-slate-200'">
                        <span class="flex items-start gap-3"><input v-model="edunexaForm.plan_uuid" type="radio" name="edunexa_plan" :value="plan.uuid" :disabled="!plan.purchasable" class="mt-1" /><span><strong class="text-lg">{{ plan.name }}</strong><span class="mt-1 block text-sm text-slate-600">{{ plan.description }}</span></span></span>
                        <p class="mt-4 text-lg font-bold">{{ plan.purchasable ? money(plan.price, plan.currency) + ' / ' + plan.billing_cycle : 'Custom quote' }}</p>
                        <div class="mt-4 divide-y divide-slate-200"><details v-for="module in plan.modules" :key="module.code" class="py-3"><summary class="feature-toggle cursor-pointer font-semibold text-slate-800"><span>{{ module.name }}</span><span class="ml-2 text-xs font-normal text-slate-500">View features</span></summary><p class="mt-2 text-sm text-slate-600">{{ module.description }}</p><ul class="mt-3 space-y-3 text-sm"><li v-for="feature in module.features" :key="feature.key" class="text-slate-700"><strong>{{ feature.name }}</strong><span class="ml-1 text-xs text-cyan-800">{{ feature.enabled ? 'Included' : (feature.ready ? 'Not included in this plan' : 'Coming soon') }}</span><span class="block text-slate-500">{{ feature.description }}</span></li></ul></details></div>
                        <p v-if="plan.slug === 'enterprise'" class="mt-4 text-sm font-semibold text-amber-700">Contact sales for custom Enterprise pricing.</p>
                    </label>
                </div>
                <div v-if="selectedEdunexaPlan?.purchasable" class="mt-5 rounded-xl bg-slate-50 p-5"><h3 class="font-bold">Invoice estimate - {{ selectedEdunexaPlan.name }}</h3><div class="mt-3 grid gap-2 text-sm sm:grid-cols-2"><p>Plan price: <strong>{{ money(selectedEdunexaPlan.price, selectedEdunexaPlan.currency) }} / {{ selectedEdunexaPlan.billing_cycle }}</strong></p><p>One-time setup: <strong>{{ money(selectedEdunexaPlan.setup_fee, selectedEdunexaPlan.currency) }}</strong></p><p>VAT ({{ vatRate }}%): <strong>{{ money(edunexaVat, selectedEdunexaPlan.currency) }}</strong></p><p>Estimated total: <strong>{{ money(edunexaAmount + edunexaVat, selectedEdunexaPlan.currency) }}</strong></p></div></div>
                <p v-for="(error, key) in edunexaForm.errors" :key="key" class="mt-3 text-sm text-red-700">{{ error }}</p>
                <label class="mt-4 flex items-center gap-2 text-sm"><input v-model="edunexaForm.use_credit" type="checkbox" /> Use available account credit</label>
                <button type="submit" :disabled="!selectedEdunexaPlan?.purchasable || edunexaForm.processing" class="mt-5 rounded-lg bg-cyan-700 px-5 py-3 font-bold text-white disabled:opacity-50">{{ edunexaForm.processing ? 'Preparing invoice...' : 'Prepare EduNexa plan invoice' }}</button>
            </form>
        </section>

        <section class="mb-8 rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
            <h2 class="text-xl font-bold">MediFlow packages</h2><p class="mt-2 text-sm text-slate-600">Select one or more packages for a single computer. The first selection is your primary package.</p>
            <div v-if="currentOrder" class="mt-4 rounded-xl bg-cyan-50 p-4 text-sm">You already have a {{ currentOrder.status }} MediFlow service. <Link v-if="currentOrder.invoice" :href="route('portal.invoices.show', currentOrder.invoice.uuid)" class="font-bold text-cyan-800 underline">View invoice {{ currentOrder.invoice.number }}</Link><p class="mt-1">Manage purchased packages below.</p><a :href="`#packages-${currentOrder.uuid}`" class="mt-3 inline-block rounded-lg bg-cyan-700 px-4 py-2 font-bold text-white">Change or add packages</a></div>
            <form v-else class="mt-5" @submit.prevent="purchase"><div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3"><PackageOption v-for="item in packages" :key="item.uuid" :item="item" :checked="form.packages.includes(item.uuid)" :disabled="!available(item) || !compatible(item)" @toggle="toggle" /></div><p v-if="!packages.length" class="text-slate-500">The MediFlow catalogue is being configured.</p><div v-if="selected.length" class="mt-5 rounded-xl bg-slate-50 p-5"><h3 class="font-bold">Your selection</h3><p class="mt-2 text-sm">{{ modules.map(item => item.name).join(', ') }}</p><div class="mt-4 flex flex-wrap gap-6 text-sm"><p>Recurring: <strong>{{ money(recurring, primary.currency) }} / {{ primary.billing_cycle }}</strong></p><p>One-time setup: <strong>{{ money(setup, primary.currency) }}</strong></p><p>Invoice total: <strong>{{ money(recurring + setup, primary.currency) }}</strong></p></div></div><p v-for="(error, key) in form.errors" :key="key" class="mt-3 text-sm text-red-700">{{ error }}</p><label class="mt-4 flex items-center gap-2 text-sm"><input v-model="form.use_credit" type="checkbox" /> Use available account credit</label><button type="submit" :disabled="!selected.length || form.processing" class="mt-5 rounded-lg bg-cyan-700 px-5 py-3 font-bold text-white disabled:opacity-50">Prepare MediFlow invoice</button></form>
        </section>

        <section v-if="orders.length" class="mb-8 space-y-3"><h2 class="text-xl font-bold">MediFlow orders and payment</h2><article v-for="order in orders" :key="order.uuid" class="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-white p-4 ring-1 ring-slate-200"><div><p class="font-semibold">{{ order.packages.join(' + ') || 'MediFlow' }}</p><p class="text-sm text-slate-600">Service: {{ order.status }}<span v-if="order.invoice"> - Invoice: {{ order.invoice.status }} - Balance {{ money(order.invoice.balance, order.invoice.currency) }}</span></p></div><Link v-if="order.invoice" :href="route('portal.invoices.show', order.invoice.uuid)" class="font-bold text-cyan-800 underline">View invoice / pay</Link></article></section>
        <section v-if="credits.length" class="mb-6 rounded-2xl bg-white p-6 ring-1 ring-slate-200"><h2 class="text-xl font-bold">Account credit</h2><p v-for="credit in credits" :key="credit.customer + credit.currency" class="mt-2">{{ credit.customer }}: <strong>{{ money(credit.balance, credit.currency) }}</strong></p><details class="mt-4"><summary class="cursor-pointer font-semibold">Recent credit activity</summary><ul class="mt-3 space-y-2"><li v-for="entry in creditHistory" :key="entry.uuid" class="text-sm">{{ entry.description }} - {{ money(entry.amount, entry.currency) }}</li></ul></details></section>
        <PackageManager v-for="order in orders.filter(item => item.package_uuids?.length || item.pending_order)" :key="order.uuid" :order="order" :packages="packages" />
        <h2 class="mb-4 text-xl font-bold">Activation codes and computers</h2><div class="space-y-5"><article v-for="license in licenses" :key="license.uuid" class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200"><div class="flex flex-wrap items-start justify-between gap-4"><div><p class="text-sm font-bold text-cyan-700">{{ license.identifier }}</p><h3 class="mt-1 text-xl font-bold">{{ license.product }}</h3><p class="mt-1 text-sm">{{ license.packages?.join(' + ') }}</p><p class="mt-1 text-sm text-slate-500">Expires {{ license.expires_at || 'with subscription' }} - {{ license.device_bound ? 'Bound to one computer' : 'Maximum ' + license.max_activations + ' activation(s)' }}</p></div><span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold uppercase">{{ license.status }}</span></div><div v-if="license.license_key" class="mt-5 rounded-xl border border-cyan-200 bg-cyan-50 p-4"><p class="text-xs font-bold uppercase tracking-wider text-cyan-800">Licence activation code</p><div class="mt-2 flex flex-wrap items-center gap-3"><code class="break-all rounded-lg bg-white px-3 py-2 font-bold text-slate-800 ring-1 ring-cyan-200">{{ license.license_key }}</code><button type="button" class="rounded-lg bg-cyan-700 px-4 py-2 text-sm font-bold text-white" @click="copy(license.license_key)">Copy code</button><a v-if="license.setup_url" :href="license.setup_url" class="rounded-lg border border-cyan-700 px-4 py-2 text-sm font-bold text-cyan-800">Download client configuration</a></div><p class="mt-2 text-xs text-slate-600">Use the activation code and private client configuration on the computer you intend to license.</p></div><div class="mt-5 overflow-x-auto"><table class="min-w-full text-left text-sm"><thead class="text-slate-500"><tr><th class="py-2">Computer</th><th>Status</th><th>Activated</th><th>Last verified</th><th></th></tr></thead><tbody><tr v-for="activation in license.activations" :key="activation.uuid" class="border-t"><td class="py-3"><p class="font-semibold">{{ activation.device_name }}</p><p class="text-xs text-slate-500">{{ [activation.device_info?.hostname, activation.device_info?.os, activation.device_info?.architecture].filter(Boolean).join(' - ') }}</p><p class="text-xs text-slate-500">{{ activation.ip_address }}</p></td><td class="uppercase">{{ activation.status }}</td><td>{{ dateTime(activation.activated_at) }}</td><td>{{ dateTime(activation.last_verified_at) }}</td><td class="text-right"><button v-if="activation.status === 'active'" type="button" class="font-bold text-red-700 hover:underline" @click="deactivate(activation)">Deactivate</button></td></tr></tbody></table><p v-if="!license.activations.length" class="py-4 text-slate-500">No computer has activated this licence.</p></div></article><p v-if="!licenses.length" class="rounded-2xl bg-white p-8 text-center text-slate-500 ring-1 ring-slate-200">Your activation code will appear here after full payment is verified.</p></div>
    </PortalLayout>
</template>
<style scoped>
.feature-toggle { display: flex; align-items: center; list-style: none; }
.feature-toggle::-webkit-details-marker { display: none; }
.feature-toggle::before { content: '+'; display: inline-flex; width: 1.35rem; height: 1.35rem; flex: 0 0 1.35rem; align-items: center; justify-content: center; margin-right: .5rem; border: 1px solid currentColor; border-radius: 9999px; font-size: 1rem; line-height: 1; }
details[open] > .feature-toggle::before { content: '\2212'; }
</style>