<script setup>
import { Link } from '@inertiajs/vue3';
defineProps({ plan: { type: Object, required: true }, productSlug: { type: String, required: true } });
const cycleLabels = { monthly: '/ month', quarterly: '/ quarter', semiannual: '/ 6 months', annual: '/ year', one_time: ' one time', custom: '' };
</script>

<template>
    <article class="flex h-full flex-col rounded-3xl border border-slate-200 bg-white p-7 shadow-sm transition hover:-translate-y-1 hover:shadow-xl">
        <div><p class="text-sm font-bold uppercase tracking-wider text-[#0797A5]">{{ plan.billing_cycle.replace('_', ' ') }}</p><h2 class="mt-2 text-2xl font-bold text-[#0B3D6E]">{{ plan.name }}</h2><p class="mt-3 min-h-12 text-sm leading-6 text-slate-600">{{ plan.description }}</p></div>
        <div class="mt-6"><template v-if="plan.price !== null && plan.price !== ''"><span class="text-4xl font-extrabold text-slate-950">{{ plan.currency }} {{ Number(plan.price).toLocaleString('en-NG', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }}</span><span class="text-sm text-slate-500">{{ cycleLabels[plan.billing_cycle] }}</span></template><span v-else class="text-3xl font-extrabold text-slate-950">Price available on request</span></div>
        <p v-if="Number(plan.setup_fee) > 0" class="mt-2 text-sm text-slate-500">Setup: {{ plan.currency }} {{ Number(plan.setup_fee).toLocaleString() }}</p>
        <div v-if="productSlug === 'edunexa' && plan.module_groups?.length" class="my-7 flex-1 divide-y divide-slate-200">
            <details v-for="module in plan.module_groups" :key="module.code" class="py-3">
                <summary class="feature-toggle cursor-pointer font-semibold text-[#0B3D6E]">{{ module.name }}</summary>
                <p class="mt-2 text-sm text-slate-600">{{ module.description }}</p>
                <ul class="mt-3 space-y-3"><li v-for="feature in module.features" :key="feature.key" class="flex gap-3 text-sm" :class="feature.enabled ? 'text-slate-700' : 'text-slate-400'"><span aria-hidden="true" :class="feature.enabled ? 'text-[#0797A5]' : 'text-slate-300'">{{ feature.enabled ? 'Included' : (feature.ready ? 'Not included' : 'Coming soon') }}</span><span><strong>{{ feature.name }}</strong><small v-if="feature.description" class="mt-1 block text-slate-500">{{ feature.description }}</small></span></li></ul>
            </details>
            <details v-if="plan.other_features?.length" class="py-3"><summary class="feature-toggle cursor-pointer font-semibold text-[#0B3D6E]">Plan limits and extras</summary><ul class="mt-3 space-y-3"><li v-for="feature in plan.other_features" :key="feature.uuid" class="text-sm text-slate-700">{{ feature.feature_name }}<strong v-if="feature.enabled && feature.limit_value">: {{ feature.limit_value }}</strong><small v-if="feature.description" class="mt-1 block text-slate-500">{{ feature.description }}</small></li></ul></details>
        </div>
        <details v-else class="my-7 flex-1"><summary class="cursor-pointer font-semibold text-[#0B3D6E]">Included features ({{ plan.features.length }})</summary><ul class="mt-4 space-y-3"><li v-for="feature in plan.features" :key="feature.uuid" class="flex gap-3 text-sm" :class="feature.enabled ? 'text-slate-700' : 'text-slate-400 line-through'"><span aria-hidden="true" :class="feature.enabled ? 'text-[#0797A5]' : 'text-slate-300'">{{ feature.enabled ? '✓' : '—' }}</span><span>{{ feature.feature_name }}<small v-if="feature.description" class="mt-1 block text-slate-500">{{ feature.description }}</small><strong v-if="feature.enabled && feature.limit_value" class="font-semibold">: {{ feature.limit_value }}</strong></span></li></ul></details>
        <p v-if="Number(plan.price) > 0" class="mb-4 text-sm font-semibold">Software + setup: {{ plan.currency }} {{ (Number(plan.price) + Number(plan.setup_fee)).toLocaleString('en-NG', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }}. Prices exclude VAT ({{ Number($page.props.billing.vat_rate) }}%), added on the invoice. Optional hosting is shown at checkout.</p><p v-if="plan.trial_days" class="mb-4 text-center text-sm font-semibold text-emerald-700">{{ plan.trial_days }}-day trial included</p>
        <Link :href="route('portal.checkout.show', { packages: [plan.id] })" class="rounded-xl bg-[#0B3D6E] px-5 py-3 text-center font-bold text-white hover:bg-[#0797A5]">Request this plan</Link>
    </article>
</template>

<style scoped>
.feature-toggle { display: flex; align-items: center; list-style: none; }
.feature-toggle::-webkit-details-marker { display: none; }
.feature-toggle::before { content: '+'; display: inline-flex; width: 1.35rem; height: 1.35rem; flex: 0 0 1.35rem; align-items: center; justify-content: center; margin-right: .5rem; border: 1px solid currentColor; border-radius: 9999px; font-size: 1rem; line-height: 1; }
details[open] > .feature-toggle::before { content: '\2212'; }
</style>