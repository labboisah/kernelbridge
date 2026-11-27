<script setup>
import PartnerLayout from '@/Layouts/PartnerLayout.vue';
import Pagination from '@/Components/Partner/Pagination.vue';
import { Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

defineProps({ subscriptions: Object });
const date = value => value ? new Date(value).toLocaleDateString() : 'Not set';
const processing = ref(null);
const generateActivationCode = subscription => {
    const message = subscription.has_issued_activation_code
        ? 'Generate a fresh code and email it to the client? Any unused code will be invalidated.'
        : 'Generate an installation activation code and email it to the client?';
    if (!confirm(message)) return;
    processing.value = subscription.uuid;
    router.post(route('partner.subscriptions.activation-code', subscription.uuid), {}, {
        preserveScroll: true,
        onFinish: () => { processing.value = null; },
    });
};
</script>

<template>
    <PartnerLayout title="Client subscriptions">
        <p class="mb-6 text-slate-600">Subscriptions for clients you introduced.</p>
        <p class="mb-6 text-sm text-slate-600">Paid direct or hybrid subscriptions can generate a fresh 24-hour installation code here. Reissuing a code invalidates any unused previous code.</p>
        <Link :href="route('partner.invoices')" class="mb-6 inline-block font-semibold text-cyan-700">Prepare a subscription invoice</Link>
        <p v-if="$page.props.flash.success" class="success-message mb-5">{{ $page.props.flash.success }}</p>
        <p v-if="$page.props.errors.activation_code || $page.props.errors.license" role="alert" class="mb-5 text-red-700">{{ $page.props.errors.activation_code || $page.props.errors.license }}</p>
        <div class="space-y-3">
            <article v-for="subscription in subscriptions.data" :key="subscription.uuid" class="rounded-xl bg-white p-5">
                <h2 class="font-bold">{{ subscription.customer.name }} · {{ subscription.product.name }}</h2>
                <p class="mt-2 text-sm text-slate-600">{{ subscription.subscription_number }} · {{ subscription.plan.name }} · {{ subscription.status }}</p>
                <p class="mt-2 text-sm text-slate-600">Starts {{ date(subscription.starts_at) }} · Ends {{ date(subscription.ends_at) }}</p>
                <button v-if="subscription.can_generate_activation_code" type="button" class="mt-4 rounded-lg bg-cyan-700 px-4 py-2 text-sm font-bold text-white disabled:opacity-50" :disabled="processing === subscription.uuid" @click="generateActivationCode(subscription)">
                    {{ processing === subscription.uuid ? 'Generating and emailing...' : (subscription.has_issued_activation_code ? 'Generate and re-email code' : 'Generate and email code') }}
                </button>
            </article>
            <p v-if="!subscriptions.data.length" class="rounded-xl bg-white p-5 text-slate-600">No client subscriptions yet.</p>
        </div>
        <Pagination :page="subscriptions" />
    </PartnerLayout>
</template>
