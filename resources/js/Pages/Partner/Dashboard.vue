<script setup>
import { computed, ref } from 'vue';
import PartnerLayout from '@/Layouts/PartnerLayout.vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({ performance: Object });
const selectedMonth = ref(null);
const maxActivity = computed(() => Math.max(1, ...(props.performance?.activity || []).map(row => row.points)));
const maxPipeline = computed(() => Math.max(1, ...(props.performance?.pipeline || []).map(row => row.count)));
const stageLabel = status => status.replaceAll('_', ' ').replace(/\b\w/g, char => char.toUpperCase());
</script>

<template>
    <PartnerLayout title="Partner overview">
        <div v-if="performance">
            <p class="mb-6 text-slate-600">Your sales activity, client portfolio and commission performance.</p>
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <Link v-for="card in [
                    { label: 'PCR prospects', value: performance.prospects, route: 'partner.pcr.index' },
                    { label: 'CRM opportunities', value: performance.leads, route: 'partner.crm.index' },
                    { label: 'Active clients', value: performance.active_clients, route: 'partner.customers' },
                    { label: 'Available effort points', value: performance.points_balance, route: 'partner.effort-points' },
                ]" :key="card.label" :href="route(card.route)" class="rounded-xl bg-white p-5 shadow-sm hover:ring-2 hover:ring-cyan-600">
                    <span class="text-sm font-semibold text-slate-600">{{ card.label }}</span>
                    <strong class="mt-2 block text-3xl text-slate-900">{{ card.value }}</strong>
                </Link>
            </div>

            <div class="mt-6 grid gap-5 lg:grid-cols-3">
                <Link :href="route('partner.nrs-commission')" class="rounded-xl bg-[#0B3D6E] p-6 text-white">
                    <h2 class="font-bold">Network qualification</h2>
                    <p class="mt-2 text-2xl font-bold">{{ performance.qualification.label }}</p>
                    <p class="mt-2 text-sm text-cyan-100">{{ performance.qualification.network_eligible ? 'Eligible for network sharing' : `${performance.qualification.clients_to_network_eligibility} more active client(s) needed for eligibility` }}</p>
                    <p v-if="!performance.network_policy_enabled" class="mt-2 text-sm text-cyan-100">Network sharing is currently disabled.</p>
                    <p v-if="performance.qualification.next_level_clients" class="mt-3 text-sm text-cyan-100">{{ performance.qualification.clients_to_next_level }} active client(s) to the next level</p>
                </Link>
                <Link v-for="[title, rows, target] in [['Personal commission earnings', performance.personal_earnings, 'partner.commission'], ['NRS commission earnings', performance.network_earnings, 'partner.nrs-commission']]" :key="title" :href="route(target)" class="rounded-xl bg-white p-6 shadow-sm hover:ring-2 hover:ring-cyan-600">
                    <h2 class="font-bold">{{ title }}</h2>
                    <p v-for="row in rows" :key="row.currency" class="mt-2 text-xl font-bold">{{ row.currency }} {{ row.total }}</p>
                    <p v-if="!rows.length" class="mt-2 text-slate-500">No earnings yet</p>
                </Link>
            </div>

            <div class="mt-6 grid gap-6 xl:grid-cols-2">
                <section class="rounded-xl bg-white p-6 shadow-sm">
                    <h2 class="text-xl font-bold">Effort activity</h2>
                    <p class="mt-2 text-sm text-slate-600">Points awarded for PCR, visits, consent and won opportunities in the last six calendar months. Redemptions are not included.</p>
                    <div class="mt-5 space-y-3">
                        <button v-for="row in performance.activity" :key="row.month" type="button" class="flex w-full items-center gap-3 rounded-lg text-left hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-cyan-700" :aria-pressed="selectedMonth === row.month" @click="selectedMonth = row.month">
                            <span class="w-20 shrink-0 text-sm">{{ row.month }}</span>
                            <span class="h-4 flex-1 overflow-hidden rounded-full bg-slate-100"><span class="block h-full rounded-full bg-cyan-700" :style="{ width: `${row.points / maxActivity * 100}%` }" /></span>
                            <strong class="w-12 text-right">{{ row.points }}</strong>
                        </button>
                    </div>
                    <p class="mt-4 text-sm text-slate-600" aria-live="polite">{{ selectedMonth ? `${selectedMonth}: ${performance.activity.find(row => row.month === selectedMonth).points} points awarded` : 'Select a month for details.' }}</p>
                    <p class="mt-1 text-xs text-slate-500">Activity is based on the effort ledger, which records activity since the points feature was introduced.</p>
                </section>
                <section class="rounded-xl bg-white p-6 shadow-sm">
                    <div class="flex flex-wrap items-center justify-between gap-2"><h2 class="text-xl font-bold">CRM pipeline</h2><Link :href="route('partner.crm.index')" class="text-sm font-semibold text-cyan-700">View pipeline</Link></div>
                    <p class="mt-2 text-sm text-slate-600">Current opportunity counts by stage, including closed opportunities.</p>
                    <div class="mt-5 space-y-3">
                        <div v-for="row in performance.pipeline" :key="row.status" class="flex items-center gap-3 text-sm">
                            <span class="w-32 shrink-0">{{ stageLabel(row.status) }}</span>
                            <span class="h-3 flex-1 overflow-hidden rounded-full bg-slate-100"><span class="block h-full rounded-full bg-[#0B3D6E]" :style="{ width: `${row.count / maxPipeline * 100}%` }" /></span>
                            <strong class="w-8 text-right">{{ row.count }}</strong>
                        </div>
                    </div>
                </section>
            </div>
        </div>
        <p v-else class="mb-6 text-slate-600">View your application and its review status under Account in the sidebar.</p>
    </PartnerLayout>
</template>
