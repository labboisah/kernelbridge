<script setup>
import AdminNavigation from '@/Components/Admin/AdminNavigation.vue';
import InputError from '@/Components/InputError.vue';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
const props = defineProps({ invoice: Object, timeline: Array });
const payment = useForm({ amount: props.invoice.balance, reference: '', method: 'bank_transfer', paid_at: new Date().toISOString().slice(0, 10), metadata: {} });
const recordPayment = () => payment.post(route('admin.invoices.payments.store', props.invoice.uuid), { onSuccess: () => payment.reset('reference') });
</script>

<template>
    <Head :title="invoice.invoice_number" />
    <PortalLayout area="Administration">
        <template #navigation><AdminNavigation active="invoices" /></template>
        <div v-if="$page.props.flash.success" class="success-message mb-4">{{ $page.props.flash.success }}</div>
        <div class="mb-5 flex flex-wrap items-start justify-between gap-3 print:hidden">
            <div><h1 class="text-3xl font-bold">{{ invoice.invoice_number }}</h1><p class="uppercase text-slate-500">{{ invoice.status }}</p></div>
            <div class="flex flex-wrap gap-2"><button class="rounded-xl border px-5 py-3 font-bold" onclick="window.print()">Print / PDF</button><Link v-if="invoice.status === 'draft'" :href="route('admin.invoices.edit', invoice.uuid)" class="rounded-xl border px-5 py-3 font-bold">Edit</Link><button v-if="invoice.status === 'draft'" class="form-button" @click="router.post(route('admin.invoices.issue', invoice.uuid))">Issue invoice</button><button v-if="!['paid','cancelled','refunded'].includes(invoice.status)" class="rounded-xl border border-red-200 px-5 py-3 font-bold text-red-700" @click="router.post(route('admin.invoices.cancel', invoice.uuid))">Cancel</button></div>
        </div>
        <div class="rounded-2xl bg-white p-8 shadow print:shadow-none">
            <div class="flex justify-between gap-6"><div><h2 class="text-2xl font-bold text-[#0B3D6E]">KernelBridge Technologies LTD</h2><p>Professional software solutions</p></div><div class="text-right"><strong>INVOICE</strong><p>Issued {{ invoice.issue_date }}</p><p>Due {{ invoice.due_date }}</p></div></div>
            <div class="my-8"><p class="text-xs uppercase text-slate-500">Bill to</p><h3 class="text-xl font-bold">{{ invoice.customer.name }}</h3><p>{{ invoice.customer.billing_address }}</p><p>{{ invoice.customer.email }}</p></div>
            <div class="overflow-x-auto"><table class="w-full"><thead><tr class="border-b"><th class="py-3 text-left">Description</th><th>Qty</th><th class="text-right">Price</th><th class="text-right">Total incl. tax</th></tr></thead><tbody><tr v-for="item in invoice.items" :key="item.uuid" class="border-b"><td class="py-4"><strong>{{ item.product }} {{ item.plan }}</strong><br/><small>{{ item.description }}</small></td><td class="text-center">{{ item.quantity }}</td><td class="text-right">{{ invoice.currency }} {{ item.unit_price }}</td><td class="text-right">{{ invoice.currency }} {{ item.total }}</td></tr></tbody></table></div>
            <dl class="ml-auto mt-6 w-full max-w-xs space-y-2"><div class="flex justify-between"><dt>Subtotal</dt><dd>{{ invoice.subtotal }}</dd></div><div class="flex justify-between"><dt>Discount</dt><dd>{{ invoice.discount }}</dd></div><div class="flex justify-between"><dt>{{ invoice.vat_rate !== null && invoice.vat_rate !== undefined ? 'VAT (' + Number(invoice.vat_rate) + '%)' : 'Tax' }}</dt><dd>{{ invoice.tax }}</dd></div><div class="flex justify-between border-t pt-2 font-bold"><dt>Total</dt><dd>{{ invoice.currency }} {{ invoice.total }}</dd></div><div class="flex justify-between"><dt>Paid</dt><dd>{{ invoice.currency }} {{ invoice.amount_paid }}</dd></div><div class="flex justify-between text-xl font-bold text-[#0B3D6E]"><dt>Balance</dt><dd>{{ invoice.currency }} {{ invoice.balance }}</dd></div></dl>
            <div v-if="invoice.notes" class="mt-10"><h4 class="font-bold">Notes</h4><p class="whitespace-pre-line text-sm">{{ invoice.notes }}</p></div>
        </div>
        <div class="mt-6 grid gap-6 lg:grid-cols-2 print:hidden">
            <section class="rounded-2xl bg-white p-6 shadow-sm"><h2 class="mb-4 text-xl font-bold">Payment history</h2><div v-if="!invoice.payments.length" class="text-slate-500">No payments recorded.</div><div v-for="item in invoice.payments" :key="item.uuid" class="border-b py-3"><div class="flex justify-between"><strong>{{ invoice.currency }} {{ item.amount }}</strong><span>{{ item.paid_at }}</span></div><p class="text-sm text-slate-500">{{ item.reference }} · {{ item.method }}</p></div><form v-if="!['draft','paid','cancelled','refunded'].includes(invoice.status)" class="mt-5 space-y-3" @submit.prevent="recordPayment"><h3 class="font-bold">Record payment</h3><div class="form-grid"><label>Amount<input v-model="payment.amount" type="number" min="0.01" step="0.01" required/><InputError :message="payment.errors.amount"/></label><label>Reference<input v-model="payment.reference" required/><InputError :message="payment.errors.reference"/></label><label>Method<select v-model="payment.method"><option value="bank_transfer">Bank transfer</option><option value="card">Card</option><option value="cash">Cash</option><option value="other">Other</option></select></label><label>Paid date<input v-model="payment.paid_at" type="date" required/></label></div><button class="form-button" :disabled="payment.processing">Record payment</button></form></section>
            <section class="rounded-2xl bg-white p-6 shadow-sm"><h2 class="mb-4 text-xl font-bold">Invoice timeline</h2><ol class="space-y-4"><li v-for="event in timeline" :key="`${event.event}-${event.at}`" class="border-l-2 border-cyan-500 pl-4"><strong>{{ event.event }}</strong><p class="text-sm text-slate-500">{{ event.at }}</p></li></ol></section>
        </div>
    </PortalLayout>
</template>
