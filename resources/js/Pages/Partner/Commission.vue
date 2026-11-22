<script setup>
import PartnerLayout from '@/Layouts/PartnerLayout.vue';
import Pagination from '@/Components/Partner/Pagination.vue';

defineProps({
    commissions: Object,
    networkCommissions: Object,
    personalEarnings: Array,
    networkEarnings: Array,
    totalEarnings: Array,
    qualification: Object,
    networkPolicyEnabled: Boolean,
    rates: Array,
    collections: Array,
    outstanding: Array,
});
</script>

<template>
    <PartnerLayout title="Commission">
        <p class="mb-6 text-slate-600">Personal commission is earned on fully paid, active subscriptions at each product's configured rate. Network commission is separately shared from the network pool. Amounts shown are balances, not payout confirmations.</p>

        <section class="mb-6 rounded-xl bg-white p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 class="text-xl font-bold">{{ qualification.label }}</h2>
                    <p class="mt-1 text-slate-600">{{ qualification.active_clients }} active client(s) · {{ qualification.network_eligible ? 'Eligible for network sharing' : `Needs ${qualification.clients_to_network_eligibility} more active client(s) for network eligibility` }}</p>
                </div>
                <p v-if="!networkPolicyEnabled" class="rounded-lg bg-amber-50 px-4 py-2 text-sm text-amber-800">Network sharing is currently disabled.</p>
            </div>
            <div v-if="qualification.next_level_clients" class="mt-5">
                <div class="mb-2 flex justify-between text-sm"><span>Progress to next level</span><span>{{ qualification.clients_to_next_level }} active client(s) to go</span></div>
                <div class="h-3 overflow-hidden rounded-full bg-slate-200"><div class="h-full rounded-full bg-cyan-700" :style="{ width: `${qualification.progress}%` }"></div></div>
            </div>
            <p v-else class="mt-4 text-sm font-semibold text-emerald-700">Top level reached.</p>
        </section>

        <div class="grid gap-5 md:grid-cols-3">
            <section v-for="[title, rows] in [['Personal client commission', personalEarnings], ['Network revenue sharing', networkEarnings], ['Total commission earnings', totalEarnings]]" :key="title" class="rounded-xl bg-white p-6">
                <h2 class="font-bold">{{ title }}</h2>
                <p v-for="row in rows" :key="row.currency" class="mt-3 text-2xl">{{ row.currency }} {{ row.total }}</p>
                <p v-if="!rows.length" class="mt-3 text-slate-500">No balance yet</p>
            </section>
            <section v-for="[title, rows] in [['Verified client collections', collections], ['Outstanding client invoices', outstanding]]" :key="title" class="rounded-xl bg-white p-6">
                <h2 class="font-bold">{{ title }}</h2>
                <p v-for="row in rows" :key="row.currency" class="mt-3 text-2xl">{{ row.currency }} {{ row.total }}</p>
                <p v-if="!rows.length" class="mt-3 text-slate-500">No balance yet</p>
            </section>
        </div>

        <section class="my-6 rounded-xl bg-white p-6">
            <h2 class="text-xl font-bold">Current product commission rates</h2>
            <p v-for="product in rates" :key="product.name" class="mt-3">{{ product.name }}: <strong>{{ product.partner_commission_rate }}%</strong></p>
        </section>

        <h2 class="mb-4 mt-8 text-xl font-bold">Personal commission history</h2>
        <article v-for="entry in commissions.data" :key="entry.id" class="mb-3 rounded-xl bg-white p-5">
            <strong>{{ entry.product.name }} · {{ entry.invoice.invoice_number }}</strong>
            <p class="mt-2">{{ entry.currency }} {{ entry.amount }} · {{ entry.status }}</p>
            <p class="mt-2 text-sm text-slate-500">{{ entry.rate }}% of {{ entry.currency }} {{ entry.base_amount }}</p>
        </article>
        <p v-if="!commissions.data.length" class="mb-4 text-slate-600">No commissions earned yet.</p>
        <Pagination :page="commissions" />

        <h2 class="mb-4 mt-8 text-xl font-bold">Network commission history</h2>
        <article v-for="entry in networkCommissions.data" :key="entry.id" class="mb-3 rounded-xl bg-white p-5">
            <strong>{{ entry.product.name }} · {{ entry.partner_level }} level</strong>
            <p class="mt-2">{{ entry.currency }} {{ entry.amount }} · {{ entry.status }}</p>
            <p class="mt-2 text-sm text-slate-500">Weight {{ entry.level_weight }} · {{ entry.pool_rate }}% of {{ entry.currency }} {{ entry.payment_base_amount }}; platform {{ entry.platform_amount }}, pool {{ entry.pool_amount }}</p>
            <p class="mt-2 text-sm text-slate-500">Recorded {{ new Date(entry.earned_at).toLocaleDateString() }}<span v-if="entry.paid_at"> · Paid {{ new Date(entry.paid_at).toLocaleDateString() }}</span></p>
        </article>
        <p v-if="!networkCommissions.data.length" class="mb-4 text-slate-600">No network commissions earned yet.</p>
        <Pagination :page="networkCommissions" />
    </PartnerLayout>
</template>
