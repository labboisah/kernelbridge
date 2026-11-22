<script setup>
import { computed, ref } from 'vue';
import { commissionSplit } from '@/Utils/commissionSplit';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import CommissionRate from '@/Components/Admin/CommissionRate.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import InputError from '@/Components/InputError.vue';
const props = defineProps({
    products: { type: Array, required: true },
    pointValue: { type: String, required: true },
    networkSettings: { type: Object, required: true },
    networkTotals: { type: Array, required: true },
    networkPartners: { type: Object, required: true },
    networkEntries: { type: Object, required: true },
});
const pointForm = useForm({ partner_point_value: props.pointValue });
const networkForm = useForm({ ...props.networkSettings });
const selectedSlug = ref(props.products[0]?.slug || '');
const selected = computed(() => props.products.find(product => product.slug === selectedSlug.value));
const amount = ref('10000.00');
const fields = [['vat_commission_rate','VAT'],['developer_commission_rate','Developer'],['platform_commission_rate','Platform'],['staff_commission_rate','Staff'],['partner_commission_rate','Agent']];
const validAmount = computed(() => /^\d{1,9}(\.\d{1,2})?$/.test(String(amount.value)));
const money = minor => (minor/100).toLocaleString('en-NG',{minimumFractionDigits:2,maximumFractionDigits:2});
const split = product => commissionSplit(amount.value, fields.map(([key])=>product[key]));
const organizationSplit = product => {
    const platform = Number(product.platform_commission_rate) + Number(product.partner_commission_rate) - Number(product.organization_partner_commission_rate);
    if (platform < 0) return null;
    return commissionSplit(amount.value, [product.vat_commission_rate, product.developer_commission_rate, platform, product.staff_commission_rate, product.organization_partner_commission_rate]);
};
const totalRate = product => fields.reduce((sum,[key])=>sum+Math.round(Number(product[key])*100),0)/100;
const markNetworkCommissionPaid = id => router.post(route('admin.partners.network-commissions.paid', id), {}, { preserveScroll: true });
</script>
<template><Head title="Product commission settings"/><PortalLayout area="Administration">
    <h1 class="text-3xl font-bold">Product commission settings</h1>
    <p class="my-5 text-slate-600">Configure separate Individual and Organization partner shares per product. The organization rate adjusts the platform share so each partner type's allocation remains 100%. Shares apply to subscription receipts including VAT, excluding hosting and credit-funded amounts. The VAT allocation is separate from the invoice VAT rate configured in VAT settings.</p>
    <Link :href="route('admin.partners.index')" class="mb-5 inline-block text-cyan-700">Partner applications</Link>
    <p v-if="$page.props.flash.success" role="status" class="success-message mb-5">{{ $page.props.flash.success }}</p>
    <section class="form-card mb-8">
        <h2 class="text-2xl font-bold">Qualified partner network revenue sharing</h2>
        <p class="my-3 text-sm text-slate-600">The network pool cap is a percentage of each eligible client payment and can never exceed its available Platform allocation. The Qualified, Premium and Elite values are relative distribution weights: the pool is split proportionally among eligible partners. Partners are eligible after the configured minimum number of active clients; level thresholds remain 1–2, 3–5 and 6+ active clients.</p>
        <form class="space-y-5" @submit.prevent="networkForm.put(route('admin.partners.network-policy.update'),{preserveScroll:true})">
            <label class="flex items-center gap-3"><input v-model="networkForm.partner_network_sharing_enabled" type="checkbox" class="h-5 w-5"/> Enable network revenue sharing</label>
            <div class="form-grid">
                <label for="network-min-clients">Minimum active clients to qualify<input id="network-min-clients" v-model="networkForm.partner_network_min_active_clients" type="number" min="1" max="100000" step="1" required/><InputError :message="networkForm.errors.partner_network_min_active_clients"/></label>
                <label for="network-pool-rate">Maximum pool (% of eligible client payment)<input id="network-pool-rate" v-model="networkForm.partner_network_pool_rate" type="number" min="0" max="100" step="0.01" required/><InputError :message="networkForm.errors.partner_network_pool_rate"/></label>
                <label for="network-qualified-weight">Qualified distribution weight<input id="network-qualified-weight" v-model="networkForm.partner_network_qualified_weight" type="number" min="0" max="1000" step="0.01" required/><InputError :message="networkForm.errors.partner_network_qualified_weight"/></label>
                <label for="network-premium-weight">Premium distribution weight<input id="network-premium-weight" v-model="networkForm.partner_network_premium_weight" type="number" min="0" max="1000" step="0.01" required/><InputError :message="networkForm.errors.partner_network_premium_weight"/></label>
                <label for="network-elite-weight">Elite distribution weight<input id="network-elite-weight" v-model="networkForm.partner_network_elite_weight" type="number" min="0" max="1000" step="0.01" required/><InputError :message="networkForm.errors.partner_network_elite_weight"/></label>
            </div>
            <button class="form-button" :disabled="networkForm.processing">{{ networkForm.processing?'Saving...':'Save network policy' }}</button>
        </form>
    </section>
    <section class="mb-8 rounded-2xl bg-white p-6 shadow-sm">
        <h2 class="text-2xl font-bold">Network liability and payouts</h2>
        <p class="mt-2 text-sm text-slate-600">Payout totals include network ledger entries explicitly marked paid by an administrator. This records payouts processed outside the platform; it does not initiate a bank transfer.</p>
        <div class="mt-5 grid gap-4 md:grid-cols-3">
            <article v-for="row in networkTotals" :key="row.currency" class="rounded-xl bg-slate-50 p-4">
                <h3 class="font-bold">{{ row.currency }}</h3>
                <p class="mt-2">Outstanding liability: <strong>{{ row.liability }}</strong></p>
                <p>Payouts recorded: <strong>{{ row.paid_out }}</strong></p>
                <p>Reversed: <strong>{{ row.reversed }}</strong></p>
            </article>
        </div>
        <p v-if="!networkTotals.length" class="mt-4 text-slate-500">No network commissions recorded.</p>
    </section>
    <section class="mb-8 overflow-x-auto rounded-2xl bg-white p-6 shadow-sm">
        <h2 class="mb-4 text-2xl font-bold">Partner qualification levels</h2>
        <table class="w-full min-w-[700px] text-left">
            <thead><tr class="border-b"><th class="py-3">Partner</th><th>Category</th><th>Active clients</th><th>Level</th><th>Network eligibility</th></tr></thead>
            <tbody><tr v-for="partner in networkPartners.data" :key="partner.user_id" class="border-b last:border-0">
                <td class="py-3"><strong>{{ partner.name }}</strong><small class="block text-slate-500">{{ partner.email }}</small></td>
                <td class="capitalize">{{ partner.partner_category }}</td><td>{{ partner.active_clients }}</td><td>{{ partner.label }}</td>
                <td>{{ partner.network_eligible ? 'Eligible' : `Needs ${partner.clients_to_network_eligibility} more active client(s)` }}</td>
            </tr></tbody>
        </table>
        <p v-if="!networkPartners.data.length" class="mt-4 text-slate-500">No approved sales partners.</p>
        <nav v-if="networkPartners.last_page > 1" aria-label="Partner qualification pagination" class="mt-5 flex gap-4">
            <Link v-if="networkPartners.prev_page_url" :href="networkPartners.prev_page_url">Previous</Link><span>Page {{ networkPartners.current_page }} of {{ networkPartners.last_page }}</span><Link v-if="networkPartners.next_page_url" :href="networkPartners.next_page_url">Next</Link>
        </nav>
    </section>
    <section class="mb-8 overflow-x-auto rounded-2xl bg-white p-6 shadow-sm">
        <h2 class="mb-4 text-2xl font-bold">Network commission ledger</h2>
        <table class="w-full min-w-[1000px] text-left">
            <thead><tr class="border-b"><th class="py-3">Recipient</th><th>Source client / partner</th><th>Invoice / product</th><th>Level</th><th>Amount</th><th>Status</th><th>Action</th></tr></thead>
            <tbody><tr v-for="entry in networkEntries.data" :key="entry.id" class="border-b last:border-0">
                <td class="py-3">{{ entry.partner.name }}</td>
                <td>{{ entry.customer.name }}<small class="block text-slate-500">Introduced by {{ entry.source_partner.name }}</small></td>
                <td>{{ entry.invoice.invoice_number }}<small class="block text-slate-500">{{ entry.product.name }}</small></td>
                <td>{{ entry.partner_level }} (weight {{ entry.level_weight }})<small class="block text-slate-500">{{ entry.pool_rate }}% of {{ entry.currency }} {{ entry.payment_base_amount }}; platform {{ entry.platform_amount }}, pool {{ entry.pool_amount }}</small></td>
                <td>{{ entry.currency }} {{ entry.amount }}</td>
                <td>{{ entry.status }}<small v-if="entry.paid_at" class="block text-slate-500">{{ entry.paid_at }}</small></td>
                <td><button v-if="entry.status === 'earned'" class="text-cyan-800 underline" @click="markNetworkCommissionPaid(entry.id)">Record payout</button><span v-else>—</span></td>
            </tr></tbody>
        </table>
        <p v-if="!networkEntries.data.length" class="mt-4 text-slate-500">No network commission transactions.</p>
        <nav v-if="networkEntries.last_page > 1" aria-label="Network ledger pagination" class="mt-5 flex gap-4">
            <Link v-if="networkEntries.prev_page_url" :href="networkEntries.prev_page_url">Previous</Link><span>Page {{ networkEntries.current_page }} of {{ networkEntries.last_page }}</span><Link v-if="networkEntries.next_page_url" :href="networkEntries.next_page_url">Next</Link>
        </nav>
    </section>
    <form class="form-card mb-8 max-w-xl" @submit.prevent="pointForm.put(route('admin.partners.point-value.update'),{preserveScroll:true})">
        <h2 class="text-xl font-bold">Partner effort point value</h2>
        <p class="my-3 text-sm text-slate-600">Set the naira bonus per point. The value is recorded when points are redeemed; changing it does not change existing bonuses.</p>
        <label for="partner-point-value">Value per point (NGN)<input id="partner-point-value" v-model="pointForm.partner_point_value" type="number" min="0.01" max="10000000" step="0.01" required/><InputError :message="pointForm.errors.partner_point_value"/></label>
        <button class="form-button" :disabled="pointForm.processing">{{ pointForm.processing?'Saving...':'Save point value' }}</button>
    </form>
    <section v-if="products.length" class="mb-8">
        <label for="commission-product" class="mb-5 block font-semibold">Select product<select id="commission-product" v-model="selectedSlug" class="mt-2 block w-full rounded-lg border-slate-300"><option v-for="product in products" :key="product.slug" :value="product.slug">{{ product.name }}</option></select></label>
        <CommissionRate v-if="selected" :key="selected.slug + JSON.stringify(fields.map(([key])=>selected[key])) + selected.organization_partner_commission_rate" :product="selected"/>
        <p class="mt-3 text-sm text-slate-500">Save changes before selecting another product. Existing earned partner commissions retain their recorded rate.</p>
    </section>
    <p v-else class="mb-6 rounded-xl bg-white p-6">Create a product to configure commission shares.</p>
    <section class="rounded-2xl bg-white p-6 shadow-sm">
        <h2 class="text-2xl font-bold">Product commission calculator</h2>
        <p class="mt-3 text-sm text-slate-600">Calculations use saved rates for the same eligible revenue amount in any one currency. These are calculation examples, not earned balances or payout records. Generated commissions use the partner type recorded when the sale is earned; historical entries are not recalculated. These totals are not payout confirmations.</p>
        <label for="commission-base" class="my-5 block max-w-sm font-semibold">Subscription receipt amount (including VAT)<input id="commission-base" v-model="amount" type="number" min="0" max="999999999.99" step="0.01" class="mt-2 block w-full rounded-lg border-slate-300"/></label>
        <p v-if="!validAmount" role="alert" class="mb-4 text-red-700">Enter a non-negative amount with up to two decimal places.</p>
        <div class="overflow-x-auto"><table class="w-full min-w-[1150px] text-left"><thead><tr class="border-b"><th class="py-3">Product</th><th v-for="[,label] in fields.slice(0,4)" :key="label" class="px-3 text-right">{{ label }}</th><th class="px-3 text-right">Individual partner</th><th class="px-3 text-right">Organization partner</th><th class="pl-3 text-right">Allocation</th><th class="pl-3 text-right">Total commission generated</th></tr></thead><tbody>
            <tr v-for="product in products" :key="product.slug" class="border-b last:border-0" :class="selectedSlug===product.slug?'bg-cyan-50':''"><th scope="row" class="py-4"><button class="text-left font-bold text-cyan-800 underline" @click="selectedSlug=product.slug">{{ product.name }}</button></th><td v-for="([key],index) in fields.slice(0,4)" :key="key" class="px-3 py-4 text-right"><strong>{{ Number(product[key]).toFixed(2) }}%</strong><span class="mt-1 block text-slate-600">{{ split(product) ? money(split(product)[index]) : '-' }}</span></td><td class="px-3 text-right"><strong>{{ Number(product.partner_commission_rate).toFixed(2) }}%</strong><span class="mt-1 block text-slate-600">{{ split(product) ? money(split(product)[4]) : '-' }}</span></td><td class="px-3 text-right"><strong>{{ Number(product.organization_partner_commission_rate).toFixed(2) }}%</strong><span class="mt-1 block text-slate-600">{{ organizationSplit(product) ? money(organizationSplit(product)[4]) : '-' }}</span></td><td class="pl-3 text-right font-bold">{{ totalRate(product).toFixed(2) }}% / 100%</td><td class="pl-4 text-right"><div v-for="entry in product.generated" :key="entry.currency" class="mb-2"><strong>{{ entry.currency }} {{ entry.total }}</strong><small class="block text-slate-500">VAT allocated: {{ entry.currency }} {{ entry.vat }}</small></div><span v-if="!product.generated.length" class="text-slate-500">No generated commissions</span></td></tr>
        </tbody></table></div>
    </section>
</PortalLayout></template>
