<script setup>
import { computed, ref } from 'vue';
import { commissionSplit } from '@/Utils/commissionSplit';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import CommissionRate from '@/Components/Admin/CommissionRate.vue';
import { Head, Link } from '@inertiajs/vue3';
const props = defineProps({ products: { type: Array, required: true } });
const selectedSlug = ref(props.products[0]?.slug || '');
const selected = computed(() => props.products.find(product => product.slug === selectedSlug.value));
const amount = ref('10000.00');
const fields = [['vat_commission_rate','VAT'],['developer_commission_rate','Developer'],['platform_commission_rate','Platform'],['staff_commission_rate','Staff'],['partner_commission_rate','Agent']];
const validAmount = computed(() => /^\d{1,9}(\.\d{1,2})?$/.test(String(amount.value)));
const money = minor => (minor/100).toLocaleString('en-NG',{minimumFractionDigits:2,maximumFractionDigits:2});
const split = product => commissionSplit(amount.value, fields.map(([key])=>product[key]));
const totalRate = product => fields.reduce((sum,[key])=>sum+Math.round(Number(product[key])*100),0)/100;
</script>
<template><Head title="Product commission settings"/><PortalLayout area="Administration">
    <h1 class="text-3xl font-bold">Product commission settings</h1>
    <p class="my-5 text-slate-600">Divide eligible subscription revenue between VAT, Developer, Platform, Staff and Agent. Each product must total 100%. Shares apply to subscription receipts including VAT, excluding hosting and credit-funded amounts. The VAT allocation is separate from the invoice VAT rate configured in VAT settings.</p>
    <Link :href="route('admin.partners.index')" class="mb-5 inline-block text-cyan-700">Partner applications</Link>
    <p v-if="$page.props.flash.success" role="status" class="success-message mb-5">{{ $page.props.flash.success }}</p>
    <section v-if="products.length" class="mb-8">
        <label for="commission-product" class="mb-5 block font-semibold">Select product<select id="commission-product" v-model="selectedSlug" class="mt-2 block w-full rounded-lg border-slate-300"><option v-for="product in products" :key="product.slug" :value="product.slug">{{ product.name }}</option></select></label>
        <CommissionRate v-if="selected" :key="selected.slug + JSON.stringify(fields.map(([key])=>selected[key]))" :product="selected"/>
        <p class="mt-3 text-sm text-slate-500">Save changes before selecting another product. Existing earned agent commissions retain their recorded rate.</p>
    </section>
    <p v-else class="mb-6 rounded-xl bg-white p-6">Create a product to configure commission shares.</p>
    <section class="rounded-2xl bg-white p-6 shadow-sm">
        <h2 class="text-2xl font-bold">Product commission calculator</h2>
        <p class="mt-3 text-sm text-slate-600">Calculations use saved rates for the same eligible revenue amount in any one currency. These are calculation examples, not earned balances or payout records. Rounding is distributed so the five amounts equal the total. Generated commissions are actual recorded Developer + Platform + Staff + Agent allocations, excluding VAT and reversed entries, grouped by currency. Historical agent earnings are included; other shares are recorded from this update onward. These totals are not payout confirmations.</p>
        <label for="commission-base" class="my-5 block max-w-sm font-semibold">Subscription receipt amount (including VAT)<input id="commission-base" v-model="amount" type="number" min="0" max="999999999.99" step="0.01" class="mt-2 block w-full rounded-lg border-slate-300"/></label>
        <p v-if="!validAmount" role="alert" class="mb-4 text-red-700">Enter a non-negative amount with up to two decimal places.</p>
        <div class="overflow-x-auto"><table class="w-full min-w-[1050px] text-left"><thead><tr class="border-b"><th class="py-3">Product</th><th v-for="[,label] in fields" :key="label" class="px-3 text-right">{{ label }}</th><th class="pl-3 text-right">Total allocation</th><th class="pl-3 text-right">Total commission generated</th></tr></thead><tbody>
            <tr v-for="product in products" :key="product.slug" class="border-b last:border-0" :class="selectedSlug===product.slug?'bg-cyan-50':''"><th scope="row" class="py-4"><button class="text-left font-bold text-cyan-800 underline" @click="selectedSlug=product.slug">{{ product.name }}</button></th><td v-for="([key],index) in fields" :key="key" class="px-3 py-4 text-right"><strong>{{ Number(product[key]).toFixed(2) }}%</strong><span class="mt-1 block text-slate-600">{{ split(product) ? money(split(product)[index]) : '-' }}</span></td><td class="pl-3 text-right font-bold">{{ totalRate(product).toFixed(2) }}%<span class="mt-1 block">{{ validAmount ? money(Math.round(Number(amount)*100)) : '-' }}</span></td><td class="pl-4 text-right"><div v-for="entry in product.generated" :key="entry.currency" class="mb-2"><strong>{{ entry.currency }} {{ entry.total }}</strong><small class="block text-slate-500">VAT allocated: {{ entry.currency }} {{ entry.vat }}</small></div><span v-if="!product.generated.length" class="text-slate-500">No generated commissions</span></td></tr>
        </tbody></table></div>
    </section>
</PortalLayout></template>
