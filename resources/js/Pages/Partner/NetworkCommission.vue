<script setup>
import PartnerLayout from '@/Layouts/PartnerLayout.vue';
import Pagination from '@/Components/Partner/Pagination.vue';

defineProps({ networkCommissions: Object, networkEarnings: Array, qualification: Object, networkPolicyEnabled: Boolean });
</script>

<template>
    <PartnerLayout title="NRS Commission">
        <p class="mb-6 text-slate-600">Network revenue sharing (NRS) is earned separately from personal client commissions. Earnings include amounts marked paid and exclude reversed transactions.</p>
        <div class="grid gap-5 md:grid-cols-2">
            <section class="rounded-xl bg-white p-6">
                <h2 class="text-xl font-bold">{{ qualification.label }}</h2>
                <p class="mt-2 text-slate-600">{{ qualification.active_clients }} active client(s) · {{ qualification.network_eligible ? 'Eligible for network sharing' : `Needs ${qualification.clients_to_network_eligibility} more active client(s) for network eligibility` }}</p>
                <p v-if="!networkPolicyEnabled" class="mt-3 text-amber-800">Network sharing is currently disabled.</p>
                <div v-if="qualification.next_level_clients" class="mt-5">
                    <p class="mb-2 text-sm">{{ qualification.clients_to_next_level }} active client(s) to the next level</p>
                    <div class="h-3 overflow-hidden rounded-full bg-slate-200"><div class="h-full rounded-full bg-cyan-700" :style="{ width: `${qualification.progress}%` }" /></div>
                </div>
                <p v-else class="mt-4 text-sm font-semibold text-emerald-700">Top level reached.</p>
            </section>
            <section class="rounded-xl bg-white p-6">
                <h2 class="text-xl font-bold">Network earnings</h2>
                <p v-for="row in networkEarnings" :key="row.currency" class="mt-3 text-2xl">{{ row.currency }} {{ row.total }}</p>
                <p v-if="!networkEarnings.length" class="mt-3 text-slate-500">No network earnings yet.</p>
            </section>
        </div>
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
