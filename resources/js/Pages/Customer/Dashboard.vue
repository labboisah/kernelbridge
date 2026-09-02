<script setup>
import { Head, Link } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import CustomerNavigation from '@/Components/Customer/CustomerNavigation.vue';

defineProps({ accounts: Array, summary: Object, subscriptions: Array, licenses: Array, invoices: Array, quotations: Array, payments: Array, tickets: Array });
const money = (value, currency) => new Intl.NumberFormat('en-NG', { style: 'currency', currency }).format(Number(value));
const date = value => value ? new Intl.DateTimeFormat('en-NG', { dateStyle: 'medium' }).format(new Date(`${value}T00:00:00`)) : '—';
</script>

<template>
  <Head title="Customer portal" />
  <PortalLayout area="Customer Portal">
    <template #navigation><CustomerNavigation active="overview" /></template>
    <div class="space-y-8">
      <section>
        <p class="text-sm font-semibold uppercase tracking-wider text-cyan-700">Customer portal</p>
        <h1 class="mt-2 text-3xl font-bold text-slate-900">Your KernelBridge account</h1>
        <p class="mt-2 text-slate-600">Subscriptions, licences, financial documents and support in one secure view.</p>
        <p v-if="accounts.length" class="mt-3 text-sm text-slate-500">{{ accounts.map(account => `${account.name} · ${account.customer_number}`).join(' | ') }}</p>
      </section>

      <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-label="Account summary">
        <article v-for="item in [['Active subscriptions',summary.active_subscriptions],['Active licences',summary.active_licenses],['Outstanding balance',summary.outstanding_balance],['Open tickets',summary.open_tickets]]" :key="item[0]" class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
          <p class="text-sm text-slate-500">{{ item[0] }}</p><p class="mt-2 text-2xl font-bold text-[#0B3D6E]">{{ item[0]==='Outstanding balance' ? `NGN ${item[1]}` : item[1] }}</p>
        </article>
      </section>

      <section class="grid gap-6 xl:grid-cols-2">
        <article class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200"><h2 class="text-xl font-bold">Subscriptions</h2><div class="mt-4 space-y-3"><div v-for="item in subscriptions" :key="item.uuid" class="flex flex-col gap-2 rounded-xl bg-slate-50 p-4 sm:flex-row sm:items-center sm:justify-between"><div><p class="font-bold">{{ item.product }} · {{ item.plan }}</p><p class="text-sm text-slate-500">{{ item.number }} · Ends {{ date(item.ends_at) }}</p></div><span class="w-fit rounded-full bg-cyan-100 px-3 py-1 text-xs font-bold uppercase text-cyan-800">{{ item.status }}</span></div><p v-if="!subscriptions.length" class="text-sm text-slate-500">No subscriptions yet.</p></div></article>
        <article class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200"><h2 class="text-xl font-bold">Licences</h2><div class="mt-4 space-y-3"><div v-for="item in licenses" :key="item.uuid" class="rounded-xl bg-slate-50 p-4"><div class="flex justify-between gap-3"><p class="font-bold">{{ item.product }}</p><span class="text-xs font-bold uppercase">{{ item.status }}</span></div><p class="mt-1 text-sm text-slate-500">Expires {{ date(item.expires_at) }} · {{ item.activations }}/{{ item.max_activations }} activations</p></div><p v-if="!licenses.length" class="text-sm text-slate-500">No licences issued yet.</p></div></article>
      </section>

      <section class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200"><h2 class="text-xl font-bold">Invoices</h2><div class="mt-4 overflow-x-auto"><table class="min-w-full text-left text-sm"><thead class="text-slate-500"><tr><th class="py-2">Invoice</th><th>Status</th><th>Due</th><th>Balance</th><th><span class="sr-only">Action</span></th></tr></thead><tbody><tr v-for="item in invoices" :key="item.uuid" class="border-t"><td class="py-3 font-semibold">{{ item.number }}</td><td class="uppercase">{{ item.status }}</td><td>{{ date(item.due_date) }}</td><td>{{ money(item.balance,item.currency) }}</td><td class="text-right"><a :href="item.url" class="font-bold text-cyan-700">View</a></td></tr></tbody></table><p v-if="!invoices.length" class="py-4 text-slate-500">No invoices yet.</p></div></section>

      <section class="grid gap-6 xl:grid-cols-2">
        <article class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200"><h2 class="text-xl font-bold">Quotations</h2><div class="mt-4 space-y-3"><div v-for="item in quotations" :key="item.uuid" class="flex items-center justify-between gap-3 border-b pb-3"><div><p class="font-semibold">{{ item.number }}</p><p class="text-sm text-slate-500">{{ money(item.total,item.currency) }} · {{ item.status }}</p></div><a :href="item.url" class="font-bold text-cyan-700">View</a></div><p v-if="!quotations.length" class="text-sm text-slate-500">No quotations yet.</p></div></article>
        <article class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200"><h2 class="text-xl font-bold">Recent payments</h2><div class="mt-4 space-y-3"><div v-for="item in payments" :key="item.uuid" class="flex justify-between gap-3 border-b pb-3"><div><p class="font-semibold">{{ item.number }}</p><p class="text-sm text-slate-500">{{ date(item.payment_date) }}</p></div><div class="text-right"><p class="font-semibold">{{ money(item.amount,item.currency) }}</p><p class="text-xs uppercase text-slate-500">{{ item.status }}</p></div></div><p v-if="!payments.length" class="text-sm text-slate-500">No payments recorded yet.</p></div></article>
      </section>

      <section class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200"><div class="flex items-center justify-between gap-4"><h2 class="text-xl font-bold">Recent support</h2><Link :href="route('support.create')" class="form-button !px-4 !py-2">New ticket</Link></div><div class="mt-4 space-y-3"><Link v-for="item in tickets" :key="item.uuid" :href="route('support.show',item.uuid)" class="block rounded-xl bg-slate-50 p-4 hover:bg-slate-100"><div class="flex justify-between gap-3"><p class="font-bold">{{ item.number }} · {{ item.subject }}</p><span class="text-xs font-bold uppercase">{{ item.status }}</span></div><p class="mt-1 text-sm text-slate-500">{{ item.product || 'General' }} · {{ item.priority }}</p></Link><p v-if="!tickets.length" class="text-sm text-slate-500">No support tickets yet.</p></div></section>
    </div>
  </PortalLayout>
</template>
