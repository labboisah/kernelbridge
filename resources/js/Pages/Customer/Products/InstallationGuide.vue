<script setup>
import CustomerNavigation from '@/Components/Customer/CustomerNavigation.vue';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    product: { type: Object, required: true },
    guideHtml: { type: String, required: true },
    invoiceUrl: { type: String, required: true },
});
</script>

<template>
    <Head :title="`${product.name} installation guide`" />
    <PortalLayout area="Customer Portal">
        <template #navigation>
            <CustomerNavigation active="invoices" />
        </template>

        <div class="mb-6">
            <Link :href="invoiceUrl" class="font-bold text-cyan-700">Back to paid invoice</Link>
            <p class="mt-6 text-sm font-bold uppercase tracking-wider text-cyan-700">Customer installation guide</p>
            <h1 class="mt-2 text-3xl font-bold text-[#0B3D6E]">{{ product.name }}</h1>
            <p class="mt-2 text-slate-600">
                Installation, activation, and initial configuration.
                <span v-if="product.version">Version {{ product.version }}</span>
            </p>
        </div>

        <article
            class="installation-guide max-w-none rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200 sm:p-10"
            v-html="guideHtml"
        />
    </PortalLayout>
</template>

<style scoped>
.installation-guide :deep(pre) { overflow-x: auto; border-radius: .75rem; background: #071426; padding: 1rem; color: #e2f5ff; }
.installation-guide :deep(code) { border-radius: .25rem; background: #f1f5f9; padding: .15rem .3rem; font-size: .9em; }
.installation-guide :deep(pre code) { background: transparent; padding: 0; color: inherit; }
.installation-guide :deep(a) { color: #0e7490; text-decoration: underline; }
</style>
