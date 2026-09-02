<script setup>
import { Link } from '@inertiajs/vue3';
defineProps({ plan: { type: Object, required: true }, productSlug: { type: String, required: true } });
const cycleLabels = { monthly: '/ month', quarterly: '/ quarter', semiannual: '/ 6 months', annual: '/ year', one_time: ' one time', custom: '' };
</script>

<template>
    <article class="flex h-full flex-col rounded-3xl border border-slate-200 bg-white p-7 shadow-sm transition hover:-translate-y-1 hover:shadow-xl">
        <div><p class="text-sm font-bold uppercase tracking-wider text-[#0797A5]">{{ plan.billing_cycle.replace('_', ' ') }}</p><h2 class="mt-2 text-2xl font-bold text-[#0B3D6E]">{{ plan.name }}</h2><p class="mt-3 min-h-12 text-sm leading-6 text-slate-600">{{ plan.description }}</p></div>
        <div class="mt-6"><template v-if="plan.billing_cycle !== 'custom'"><span class="text-4xl font-extrabold text-slate-950">{{ plan.currency }} {{ Number(plan.price).toLocaleString() }}</span><span class="text-sm text-slate-500">{{ cycleLabels[plan.billing_cycle] }}</span></template><span v-else class="text-3xl font-extrabold text-slate-950">Custom pricing</span></div>
        <p v-if="Number(plan.setup_fee) > 0" class="mt-2 text-sm text-slate-500">Setup: {{ plan.currency }} {{ Number(plan.setup_fee).toLocaleString() }}</p>
        <ul class="my-7 flex-1 space-y-3"><li v-for="feature in plan.features" :key="feature.uuid" class="flex gap-3 text-sm" :class="feature.enabled ? 'text-slate-700' : 'text-slate-400 line-through'"><span aria-hidden="true" :class="feature.enabled ? 'text-[#0797A5]' : 'text-slate-300'">{{ feature.enabled ? '✓' : '—' }}</span><span>{{ feature.feature_name }}<strong v-if="feature.enabled && feature.limit_value" class="font-semibold">: {{ feature.limit_value }}</strong></span></li></ul>
        <p v-if="plan.trial_days" class="mb-4 text-center text-sm font-semibold text-emerald-700">{{ plan.trial_days }}-day trial included</p>
        <Link :href="route('demo', { product: productSlug })" class="rounded-xl bg-[#0B3D6E] px-5 py-3 text-center font-bold text-white hover:bg-[#0797A5]">Request this plan</Link>
    </article>
</template>
