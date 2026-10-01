<script setup>
import PortalLayout from '@/Layouts/PortalLayout.vue';
import PartnerReview from '@/Components/Admin/PartnerReview.vue';
import { Head, Link } from '@inertiajs/vue3';
defineProps({ applications: Object, types: Object });
</script>
<template><Head title="Partner applications"/><PortalLayout area="Administration"><div class="mb-6"><h1 class="text-3xl font-bold">Partner applications</h1><p class="mt-2 text-slate-600">Review partner profiles and provide next steps.</p><Link :href="route('admin.partners.commissions')" class="mt-4 inline-block font-bold text-cyan-700">Configure product commissions</Link></div>
    <p v-if="$page.props.flash.success" role="status" class="success-message">{{ $page.props.flash.success }}</p>
    <p v-if="!applications.data.length" class="rounded-xl bg-white p-8">No submitted applications yet.</p>
    <div class="space-y-6"><PartnerReview v-for="application in applications.data" :key="application.id" :application="application" :label="types[application.type]?.label || 'Customer (legacy application)'"/></div>
    <nav aria-label="Pagination" class="mt-6 flex gap-4"><Link v-if="applications.prev_page_url" :href="applications.prev_page_url">Previous</Link><span>Page {{ applications.current_page }} of {{ applications.last_page }}</span><Link v-if="applications.next_page_url" :href="applications.next_page_url">Next</Link></nav>
</PortalLayout></template>
