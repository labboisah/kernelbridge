<script setup>
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import CustomerNavigation from '@/Components/Customer/CustomerNavigation.vue';
const props=defineProps({plans:Array,customer:Object,hosting:Object});
const form=useForm({packages:props.plans.map(p=>p.uuid),implementation:{mode:'local',domain:''}});
const primary=computed(()=>props.plans[0]);
const months=computed(()=>({monthly:1,quarterly:3,semiannual:6,annual:12}[primary.value.billing_cycle] || 0));
const onlineAvailable=computed(()=>props.hosting.enabled && props.hosting.currency===primary.value.currency && months.value>0);
const software=computed(()=>props.plans.reduce((n,p)=>n+Math.round(Number(p.price)*100),0));
const setup=computed(()=>props.plans.reduce((n,p)=>n+Math.round(Number(p.setup_fee)*100),0));
const hostingTotal=computed(()=>form.implementation.mode==='online' ? Math.round(Number(props.hosting.monthly_price)*100)*months.value : 0);
const total=computed(()=>software.value+setup.value+hostingTotal.value);
const priced=computed(()=>props.plans.every(p=>Number(p.price)>0));
const money=cents=>new Intl.NumberFormat('en-NG',{style:'currency',currency:primary.value.currency}).format(cents/100);
</script>
<template>
<Head title="Confirm your plan"/><PortalLayout><template #navigation><CustomerNavigation/></template>
<div class="mx-auto max-w-4xl space-y-6"><header><h1 class="text-3xl font-bold">Confirm your plan and implementation</h1><p class="mt-2 text-slate-600">Your account: {{ customer.name }} ({{ customer.email }})</p></header>
<form @submit.prevent="form.post(route('portal.checkout.store'))" class="space-y-6">
<section class="rounded-2xl bg-white p-6 shadow-sm"><h2 class="text-xl font-bold">Selected packages</h2><article v-for="plan in plans" :key="plan.uuid" class="mt-4 border-t pt-4"><h3 class="font-bold">{{ plan.product }} / {{ plan.name }}</h3><p class="text-sm text-slate-600">{{ plan.description }}</p><p class="mt-2">{{ Number(plan.price)>0 ? money(Math.round(Number(plan.price)*100)) : 'Contact sales for pricing' }} / {{ plan.billing_cycle }}</p><details class="mt-3"><summary class="cursor-pointer font-semibold">Included features</summary><ul class="mt-2 space-y-2"><li v-for="feature in plan.features" :key="feature.key"><strong>{{ feature.name }}</strong><span v-if="feature.description">: {{ feature.description }}</span></li></ul></details></article></section>
<section class="rounded-2xl bg-white p-6 shadow-sm"><h2 class="text-xl font-bold">Implementation</h2><label class="mt-4 flex gap-3"><input v-model="form.implementation.mode" type="radio" value="local"/><span><strong>Local installation</strong><br/>Run on your licensed computer. No domain or VPS charge.</span></label><label class="mt-4 flex gap-3"><input v-model="form.implementation.mode" type="radio" value="online" :disabled="!onlineAvailable"/><span><strong>Online with domain and VPS</strong><br/>{{ hosting.currency }} {{ Number(hosting.monthly_price).toLocaleString() }} / month, combined.<span v-if="!onlineAvailable" class="block text-amber-800">Contact sales: unavailable for this currency or billing period.</span></span></label>
<div v-if="form.implementation.mode==='online'" class="mt-4 rounded-xl bg-cyan-50 p-4"><p>{{ months }} months prepaid with this subscription: <strong>{{ money(hostingTotal) }}</strong>. Hosting renews with the software term at the contracted rate.</p><label class="mt-3 block">Preferred domain (optional)<input v-model="form.implementation.domain" placeholder="yourclinic.com" class="mt-1 block w-full rounded-lg border-slate-300"/></label><p class="mt-2 text-sm">Domain availability and VPS provisioning are confirmed by support after payment. Your licence binds to the server where the app is activated. Hosting fees are separate from package-removal credits.</p></div></section>
<section class="rounded-2xl bg-white p-6 shadow-sm"><h2 class="text-xl font-bold">Payment confirmation</h2><dl class="mt-4 space-y-3"><div class="flex justify-between"><dt>Software / {{ primary.billing_cycle }}</dt><dd>{{ money(software) }}</dd></div><div class="flex justify-between"><dt>One-time setup</dt><dd>{{ money(setup) }}</dd></div><div class="flex justify-between"><dt>Domain and VPS / {{ months }} months</dt><dd>{{ money(hostingTotal) }}</dd></div><div class="flex justify-between border-t pt-3 text-xl font-bold"><dt>Total due</dt><dd>{{ money(total) }}</dd></div></dl><p class="mt-4 text-sm">Confirm to create the invoice in your account. On the invoice, proceed to secure payment with Flutterwave. Full verified payment generates the activation code and private client configuration.</p><p v-for="(error,key) in form.errors" :key="key" role="alert" class="mt-3 text-red-700">{{ error }}</p><button :disabled="form.processing || !priced" class="mt-5 rounded-xl bg-cyan-700 px-6 py-3 font-bold text-white disabled:opacity-50">{{ form.processing ? 'Preparing invoice...' : 'Confirm and continue to payment' }}</button><Link v-if="!priced" :href="route('contact')" class="ml-4 underline">Request pricing</Link></section>
</form></div></PortalLayout>
</template>
