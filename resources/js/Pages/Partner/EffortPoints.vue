<script setup>
import { computed } from 'vue';
import PartnerLayout from '@/Layouts/PartnerLayout.vue';
import Pagination from '@/Components/Partner/Pagination.vue';

const props = defineProps({ points: Object, pointBonuses: Array, pointEntries: Object, comparison: Array, comparisonAvailable: Boolean });
const eventLabels = {
    pcr_registered: 'Prospect registered',
    visit_recorded: 'Visit / contact recorded',
    consent_recorded: 'Consent and CRM registration',
    opportunity_won: 'CRM opportunity won',
    payment_redemption: 'Bonus applied to verified payment',
};
const maximum = computed(() => Math.max(1, ...props.comparison.flatMap(row => [row.your_points, row.other_average])));
</script>

<template>
    <PartnerLayout title="Effort Points">
        <p class="mb-6 text-slate-600">Earn points for registering prospects, recording visits, getting consent, and winning CRM opportunities. Points convert to a bonus on your next verified NGN client payment, up to the payment amount; unused points carry forward.</p>

        <div class="mb-6 grid gap-5 md:grid-cols-2">
            <section class="rounded-xl bg-white p-6">
                <h2 class="font-bold">Available points</h2>
                <p class="mt-3 text-3xl font-bold">{{ points.balance }}</p>
                <p class="mt-1 text-slate-600">Current value: NGN {{ points.value }} per point</p>
            </section>
            <section class="rounded-xl bg-white p-6">
                <h2 class="font-bold">Bonus commission earned</h2>
                <p v-for="bonus in pointBonuses" :key="bonus.currency" class="mt-3 text-2xl">{{ bonus.currency }} {{ bonus.total }}</p>
                <p v-if="!pointBonuses.length" class="mt-3 text-slate-500">No effort bonuses earned yet</p>
            </section>
        </div>

        <section class="mb-8 rounded-xl bg-white p-6">
            <h2 class="text-xl font-bold">Effort comparison</h2>
            <p class="mt-2 text-sm text-slate-600">Your points compared with the average points per milestone for other approved partners. Other partners are not individually identified.</p>
            <p v-if="!comparisonAvailable" class="mt-4 rounded-lg bg-slate-50 p-4 text-slate-600">Anonymous comparison will appear when at least three other approved partners are eligible for the benchmark.</p>
            <div v-else class="mt-5 space-y-5">
                <div v-for="row in comparison" :key="row.event_type" class="space-y-2">
                    <h3 class="font-semibold">{{ row.label }}</h3>
                    <div class="flex items-center gap-3 text-sm">
                        <span class="w-32 shrink-0 text-slate-600">Your effort</span>
                        <div class="h-4 flex-1 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-[#0B3D6E]" :style="{ width: `${row.your_points ? Math.max(1, row.your_points / maximum * 100) : 0}%` }" /></div>
                        <strong class="w-12 text-right">{{ row.your_points }}</strong>
                    </div>
                    <div class="flex items-center gap-3 text-sm">
                        <span class="w-32 shrink-0 text-slate-600">Other partners avg.</span>
                        <div class="h-4 flex-1 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-cyan-600" :style="{ width: `${row.other_average ? Math.max(1, row.other_average / maximum * 100) : 0}%` }" /></div>
                        <strong class="w-12 text-right">{{ row.other_average.toFixed(1) }}</strong>
                    </div>
                </div>
                <div class="flex flex-wrap gap-5 border-t pt-4 text-sm">
                    <span><span class="mr-2 inline-block h-3 w-3 rounded-full bg-[#0B3D6E]" />Your effort</span>
                    <span><span class="mr-2 inline-block h-3 w-3 rounded-full bg-cyan-600" />Other partners' average</span>
                </div>
            </div>
        </section>

        <h2 class="mb-4 text-xl font-bold">Your effort history</h2>
        <article v-for="entry in pointEntries.data" :key="entry.id" class="mb-3 rounded-xl bg-white p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <strong>{{ eventLabels[entry.event_type] || entry.event_type }}</strong>
                    <p v-if="entry.prospect" class="mt-1 text-sm text-slate-600">{{ entry.prospect.organization || entry.prospect.name }}</p>
                    <p v-else-if="entry.lead" class="mt-1 text-sm text-slate-600">{{ entry.lead.lead_number }}</p>
                    <p v-else-if="entry.payment" class="mt-1 text-sm text-slate-600">{{ entry.payment.payment_number }}</p>
                    <p v-else-if="entry.invoice_payment" class="mt-1 text-sm text-slate-600">{{ entry.invoice_payment.reference }}</p>
                </div>
                <span class="font-semibold" :class="entry.points < 0 ? 'text-amber-700' : 'text-emerald-700'">{{ entry.points > 0 ? '+' : '' }}{{ entry.points }} points</span>
            </div>
            <p v-if="entry.amount" class="mt-2">{{ entry.currency }} {{ entry.amount }} at NGN {{ entry.point_value }} per point</p>
            <p class="mt-1 text-sm capitalize text-slate-500">{{ entry.status }}</p>
        </article>
        <p v-if="!pointEntries.data.length" class="mb-4 text-slate-600">No effort points earned yet.</p>
        <Pagination :page="pointEntries" />
    </PartnerLayout>
</template>
