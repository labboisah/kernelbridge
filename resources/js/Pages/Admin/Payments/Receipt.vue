<script setup>
import DocumentMarks from '@/Components/DocumentMarks.vue';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({ payment: Object, documentAssets: Object });
</script>

<template>
    <Head :title="`Receipt ${payment.payment_number}`" />
    <PortalLayout area="Administration">
        <div class="mb-5 flex flex-wrap gap-3 print:hidden">
            <Link :href="route('admin.payments.show', payment.uuid)" class="rounded-xl border px-5 py-3 font-bold">Back to payment</Link>
            <button type="button" class="form-button" onclick="window.print()">Print / PDF</button>
        </div>
        <article class="rounded-2xl bg-white p-8 shadow print:shadow-none">
            <div class="flex justify-between gap-6">
                <div><h1 class="text-2xl font-bold text-[#0B3D6E]">KernelBridge Technologies LTD</h1><p>Professional software solutions</p></div>
                <div class="text-right"><strong>PAYMENT RECEIPT</strong><p>{{ payment.payment_number }}</p></div>
            </div>
            <div class="my-8"><p class="text-xs uppercase text-slate-500">Received from</p><h2 class="text-xl font-bold">{{ payment.customer.name }}</h2><p>{{ payment.customer.email }}</p></div>
            <dl class="grid gap-4 sm:grid-cols-2">
                <div><dt class="text-sm text-slate-500">Amount received</dt><dd class="font-bold">{{ payment.currency }} {{ payment.amount }}</dd></div>
                <div><dt class="text-sm text-slate-500">Payment date</dt><dd>{{ payment.payment_date }}</dd></div>
                <div><dt class="text-sm text-slate-500">Method</dt><dd>{{ payment.payment_method }}</dd></div>
                <div><dt class="text-sm text-slate-500">Reference</dt><dd>{{ payment.transaction_reference }}</dd></div>
            </dl>
            <h3 class="mt-8 font-bold">Applied to invoices</h3>
            <div v-for="allocation in payment.allocations" :key="allocation.uuid" class="flex justify-between border-b py-2">
                <span>{{ allocation.invoice.invoice_number }}</span><span>{{ payment.currency }} {{ allocation.amount }}</span>
            </div>
            <DocumentMarks :assets="documentAssets" />
        </article>
    </PortalLayout>
</template>
