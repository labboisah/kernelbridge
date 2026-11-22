<script setup>
import PartnerLayout from '@/Layouts/PartnerLayout.vue';
import Pagination from '@/Components/Partner/Pagination.vue';

defineProps({ codes: Object });
const date = value => value ? new Date(value).toLocaleString() : 'Not set';
</script>

<template>
    <PartnerLayout title="Client activation codes">
        <p class="mb-6 text-slate-600">View activation code status for your clients. Codes are shown only once when issued and cannot be retrieved here; clients manage code issuance in their own portal.</p>
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
