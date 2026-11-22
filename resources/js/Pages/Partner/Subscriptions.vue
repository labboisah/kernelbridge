<script setup>
import PartnerLayout from '@/Layouts/PartnerLayout.vue';
import Pagination from '@/Components/Partner/Pagination.vue';
import { Link } from '@inertiajs/vue3';

defineProps({ subscriptions: Object });
const date = value => value ? new Date(value).toLocaleDateString() : 'Not set';
</script>

<template>
    <PartnerLayout title="Client subscriptions">
        <p class="mb-6 text-slate-600">Subscriptions for clients you introduced.</p>
        <Link :href="route('partner.invoices')" class="mb-6 inline-block font-semibold text-cyan-700">Prepare a subscription invoice</Link>
        <div class="space-y-3">
            <article v-for="subscription in subscriptions.data" :key="subscription.uuid" class="rounded-xl bg-white p-5">
                <h2 class="font-bold">{{ subscription.customer.name }} · {{ subscription.product.name }}</h2>
                <p class="mt-2 text-sm text-slate-600">{{ subscription.subscription_number }} · {{ subscription.plan.name }} · {{ subscription.status }}</p>
                <p class="mt-2 text-sm text-slate-600">Starts {{ date(subscription.starts_at) }} · Ends {{ date(subscription.ends_at) }}</p>
            </article>
            <p v-if="!subscriptions.data.length" class="rounded-xl bg-white p-5 text-slate-600">No client subscriptions yet.</p>
        </div>
        <Pagination :page="subscriptions" />
    </PartnerLayout>
</template>
