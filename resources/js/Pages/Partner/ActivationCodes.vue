<script setup>
import PartnerLayout from '@/Layouts/PartnerLayout.vue';
import Pagination from '@/Components/Partner/Pagination.vue';
import { Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

defineProps({ codes: Object, subscriptions: Array });
const date = value => value ? new Date(value).toLocaleString() : 'Not set';
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
const copy = async code => {
    try {
        await navigator.clipboard.writeText(code);
    } catch {
        alert('Select and copy the activation code manually.');
    }
};
</script>

<template>
    <PartnerLayout title="Client activation codes">
        <p class="mb-6 text-slate-600">View code status and generate or replace a code for eligible paid client subscriptions. New codes are emailed to the client, shown here once, and expire after 24 hours.</p>
        <p v-if="$page.props.flash.success" class="success-message mb-5">{{ $page.props.flash.success }}</p>
        <section v-if="$page.props.flash.activation_code" class="mb-6 rounded-xl border border-emerald-300 bg-emerald-50 p-5">
            <h2 class="font-bold text-emerald-900">New installation activation code</h2>
            <p class="mt-1 text-sm text-emerald-800">
                {{ $page.props.flash.activation_code.product }} for {{ $page.props.flash.activation_code.customer }}.
                This code is shown once, expires after 24 hours, and replaces any previous unused code.
            </p>
            <div class="mt-3 flex flex-wrap items-center gap-3">
                <code class="rounded-lg bg-white px-3 py-2 font-bold ring-1 ring-emerald-300">{{ $page.props.flash.activation_code.code }}</code>
                <button type="button" class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-bold text-white" @click="copy($page.props.flash.activation_code.code)">Copy code</button>
            </div>
            <p class="mt-2 text-xs text-emerald-800">Expires {{ date($page.props.flash.activation_code.expires_at) }}. Keep this code private and share it securely with the client.</p>
        </section>
        <p v-if="$page.props.errors.activation_code || $page.props.errors.license" role="alert" class="mb-5 text-red-700">{{ $page.props.errors.activation_code || $page.props.errors.license }}</p>
        <section class="mb-8">
            <h2 class="mb-3 text-xl font-bold">Paid subscriptions eligible for activation codes</h2>
            <div class="space-y-3">
                <article v-for="subscription in subscriptions" :key="subscription.uuid" class="flex flex-wrap items-center justify-between gap-4 rounded-xl bg-white p-5">
                    <div>
                        <h3 class="font-bold">{{ subscription.customer }} · {{ subscription.product }}</h3>
                        <p class="mt-1 text-sm text-slate-600">{{ subscription.subscription_number }}</p>
                    </div>
                    <button type="button" class="rounded-lg bg-cyan-700 px-4 py-2 text-sm font-bold text-white disabled:opacity-50" :disabled="processing === subscription.uuid" @click="generateActivationCode(subscription)">
                        {{ processing === subscription.uuid ? 'Generating and emailing...' : (subscription.has_issued_activation_code ? 'Generate and re-email code' : 'Generate and email code') }}
                    </button>
                </article>
                <p v-if="!subscriptions.length" class="rounded-xl bg-white p-5 text-slate-600">No paid, active direct or hybrid subscriptions are currently eligible for an installation code.</p>
            </div>
        </section>
        <h2 class="mb-3 text-xl font-bold">Activation code history</h2>
        <div class="space-y-3">
            <article v-for="code in codes.data" :key="code.uuid" class="rounded-xl bg-white p-5">
                <h2 class="font-bold">{{ code.customer }} · {{ code.product }}</h2>
                <p class="mt-2 text-sm capitalize text-slate-600">{{ code.status }}</p>
                <p class="mt-2 text-sm text-slate-600">Issued {{ date(code.created_at) }} · Expires {{ date(code.expires_at) }}</p>
            </article>
            <p v-if="!codes.data.length" class="rounded-xl bg-white p-5 text-slate-600">No client activation codes have been issued.</p>
        </div>
        <Pagination :page="codes" />
    </PartnerLayout>
</template>
