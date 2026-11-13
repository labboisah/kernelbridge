<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
const props = defineProps({ clientSetup: { type: Object, default: null }, availableCredit: { type: String, default: '0.00' }, invoice: Object, canPayOnline: { type: Boolean, default: false }, portal: { type: Boolean, default: false } });
const pay = () => router.post(route('portal.invoices.pay-online', props.invoice.uuid));
</script>
<template>
    <Head :title="invoice.invoice_number" />
    <main class="min-h-screen bg-slate-100 p-4 sm:p-10">
        <div class="mx-auto mb-4 flex max-w-4xl flex-wrap justify-end gap-3 print:hidden">
            <Link v-if="portal" :href="route('portal.invoices.index')" class="rounded-xl border border-slate-300 bg-white px-5 py-3 font-bold text-[#0B3D6E]">Back to invoices</Link>
            <Link v-if="portal && invoice.status === 'paid'" :href="route('portal.licenses.index')" class="rounded-xl bg-cyan-700 px-5 py-3 font-bold text-white">Activation codes</Link>
            <Link v-if="portal && Number(invoice.balance) > 0" :href="route('portal.payments.create')" class="rounded-xl border border-slate-300 bg-white px-5 py-3 font-bold text-slate-800">Submit payment proof</Link>
            <button v-if="portal && Number(availableCredit) > 0 && Number(invoice.balance) > 0" type="button" class="rounded-xl border border-cyan-700 bg-white px-5 py-3 font-bold text-cyan-800" @click="router.post(route('portal.invoices.credit', invoice.uuid))">Apply credit ({{ invoice.currency }} {{ availableCredit }})</button>
            <button v-if="canPayOnline" type="button" class="rounded-xl bg-[#0797A5] px-5 py-3 font-bold text-white hover:bg-[#067f8b]" @click="pay">Proceed to payment with Flutterwave</button>
            <button class="rounded-xl bg-[#0B3D6E] px-5 py-3 font-bold text-white" onclick="window.print()">Print / Save PDF</button>
        </div>
        <p v-if="$page.props.flash.success" class="success-message mx-auto mb-4 max-w-4xl">{{ $page.props.flash.success }}</p>
        <p v-if="$page.props.errors.payment || $page.props.errors.invoice" role="alert" class="mx-auto mb-4 max-w-4xl text-red-700">{{ $page.props.errors.payment || $page.props.errors.invoice }}</p>
        <section v-if="clientSetup?.activation_ready" class="mx-auto mb-6 max-w-4xl rounded-2xl bg-emerald-50 p-6 print:hidden">
            <h2 class="text-xl font-bold">Payment confirmed: generate your installation code</h2>
            <p class="mt-3">Generate a one-time installation code from your activation codes page. It will be shown once and expires after 24 hours.</p>
            <div class="mt-4 flex flex-wrap gap-3">
                <a v-if="clientSetup.archive" :href="clientSetup.archive.url" class="inline-flex items-center gap-2 rounded-xl bg-cyan-700 px-5 py-3 font-bold text-white">Download {{ clientSetup.archive.product_name || 'product' }} {{ clientSetup.archive.version }}</a>
                <Link v-if="clientSetup.guide_url" :href="clientSetup.guide_url" class="inline-flex items-center rounded-xl border border-emerald-700 px-5 py-3 font-bold text-emerald-800">Open installation guide</Link>
                <a v-if="clientSetup.environment_url" :href="clientSetup.environment_url" class="inline-flex items-center rounded-xl border border-emerald-700 px-5 py-3 font-bold text-emerald-800">Download licensing configuration</a>
                <Link :href="route('portal.licenses.index')" class="inline-flex items-center rounded-xl border border-emerald-700 px-5 py-3 font-bold text-emerald-800">Generate installation code</Link>
            </div>
            <p v-if="clientSetup.archive?.sha256" class="mt-3 break-all text-xs text-emerald-900">Archive SHA-256: {{ clientSetup.archive.sha256 }}</p>
        </section>
        <section v-else-if="clientSetup?.license_key" class="mx-auto mb-6 max-w-4xl rounded-2xl bg-emerald-50 p-6 print:hidden">
            <h2 class="text-xl font-bold">Payment confirmed: activate your service</h2>
            <p class="mt-3">Legacy license key: <code class="break-all font-bold">{{ clientSetup.license_key }}</code></p>
            <p class="mt-3 text-sm">Enter this legacy key in the licensing screen for applications configured for legacy key activation.</p>
            <div class="mt-4 flex flex-wrap gap-3">
                <a v-if="clientSetup.archive" :href="clientSetup.archive.url" class="rounded-xl bg-cyan-700 px-5 py-3 font-bold text-white">Download {{ clientSetup.archive.product_name || 'product' }} {{ clientSetup.archive.version }}</a>
                <Link v-if="clientSetup.guide_url" :href="clientSetup.guide_url" class="rounded-xl border border-emerald-700 px-5 py-3 font-bold text-emerald-800">Open installation guide</Link>
            </div>
        </section>
        <article class="mx-auto max-w-4xl rounded-2xl bg-white p-6 shadow-sm sm:p-12 print:shadow-none">
            <div class="flex justify-between gap-6">
                <div><img src="/images/2.png" alt="KernelBridge Technologies" class="mb-3 h-20 w-20 object-contain mix-blend-multiply" /><h1 class="text-xl font-bold text-[#0B3D6E]">KernelBridge Technologies LTD</h1></div>
                <div class="text-right"><p class="text-2xl font-bold">INVOICE</p><p>{{ invoice.invoice_number }}</p><p class="mt-2 text-sm">Due {{ invoice.due_date }}</p><span class="mt-2 inline-block rounded-full bg-slate-100 px-3 py-1 text-xs font-bold uppercase">{{ invoice.status }}</span></div>
            </div>
            <div class="my-10"><p class="text-xs font-bold uppercase text-slate-500">Bill to</p><h2 class="text-xl font-bold">{{ invoice.customer.name }}</h2><p>{{ invoice.customer.billing_address }}</p></div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[560px]">
                    <thead><tr class="border-b"><th class="py-3 text-left">Description</th><th>Qty</th><th class="text-right">Price</th><th class="text-right">Total incl. tax</th></tr></thead>
                    <tbody><tr v-for="item in invoice.items" :key="item.uuid" class="border-b"><td class="py-4">{{ item.description }}</td><td class="text-center">{{ item.quantity }}</td><td class="text-right">{{ invoice.currency }} {{ item.unit_price }}</td><td class="text-right">{{ invoice.currency }} {{ item.total }}</td></tr></tbody>
                </table>
            </div>
            <dl class="ml-auto mt-6 max-w-xs space-y-2">
                <div class="flex justify-between"><dt>Subtotal</dt><dd>{{ invoice.currency }} {{ invoice.subtotal }}</dd></div>
                <div class="flex justify-between"><dt>Discount</dt><dd>{{ invoice.currency }} {{ invoice.discount }}</dd></div>
                <div class="flex justify-between"><dt>{{ invoice.vat_rate !== null && invoice.vat_rate !== undefined ? 'VAT (' + Number(invoice.vat_rate) + '%)' : 'Tax' }}</dt><dd>{{ invoice.currency }} {{ invoice.tax }}</dd></div>
                <div class="flex justify-between"><dt>Total (including tax)</dt><dd>{{ invoice.currency }} {{ invoice.total }}</dd></div>
                <div class="flex justify-between"><dt>Paid</dt><dd>{{ invoice.currency }} {{ invoice.amount_paid }}</dd></div>
                <div class="flex justify-between border-t pt-2 text-xl font-bold text-[#0B3D6E]"><dt>Balance due</dt><dd>{{ invoice.currency }} {{ invoice.balance }}</dd></div>
            </dl>
            <div v-if="invoice.payments?.length" class="mt-10 border-t pt-6">
                <h3 class="font-bold">Payment history</h3>
                <div v-for="payment in invoice.payments" :key="payment.uuid" class="mt-2 flex justify-between text-sm"><span>{{ payment.reference }} · {{ payment.method }}</span><strong>{{ payment.currency }} {{ payment.amount }}</strong></div>
            </div>
            <div v-if="invoice.notes" class="mt-10 border-t pt-6"><h3 class="font-bold">Notes</h3><p class="whitespace-pre-line">{{ invoice.notes }}</p></div>
            <div v-if="invoice.terms" class="mt-6"><h3 class="font-bold">Terms</h3><p class="whitespace-pre-line text-sm">{{ invoice.terms }}</p></div>
            <p class="mt-12 text-center text-sm text-slate-500">Thank you for choosing KernelBridge Technologies.</p>
        </article>
    </main>
</template>
