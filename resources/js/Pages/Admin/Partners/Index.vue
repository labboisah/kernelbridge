<script setup>
import PortalLayout from '@/Layouts/PortalLayout.vue';
import PartnerReview from '@/Components/Admin/PartnerReview.vue';
import PartnerOrganizationUpgradeReview from '@/Components/Admin/PartnerOrganizationUpgradeReview.vue';
import { Head, Link } from '@inertiajs/vue3';
defineProps({ applications: Object, organizationUpgrades: Object, types: Object, questionnaire: Array });
</script>
<template><Head title="Partner applications"/><PortalLayout area="Administration"><div class="mb-6"><h1 class="text-3xl font-bold">Partner applications</h1><p class="mt-2 text-slate-600">Review partner profiles and provide next steps.</p><Link :href="route('admin.partners.commissions')" class="mt-4 inline-block font-bold text-cyan-700">Configure product commissions</Link></div>
    <p v-if="$page.props.flash.success" role="status" class="success-message">{{ $page.props.flash.success }}</p>
    <section class="my-10"><h2 class="mb-4 text-2xl font-bold">Organization partner upgrades</h2><p v-if="!organizationUpgrades.data.length" class="rounded-xl bg-white p-6">No organization upgrade requests yet.</p><div class="space-y-6"><PartnerOrganizationUpgradeReview v-for="upgrade in organizationUpgrades.data" :key="upgrade.id" :upgrade="upgrade"/></div><nav v-if="organizationUpgrades.last_page > 1" aria-label="Organization upgrade pagination" class="mt-6 flex gap-4"><Link v-if="organizationUpgrades.prev_page_url" :href="organizationUpgrades.prev_page_url">Previous</Link><span>Page {{ organizationUpgrades.current_page }} of {{ organizationUpgrades.last_page }}</span><Link v-if="organizationUpgrades.next_page_url" :href="organizationUpgrades.next_page_url">Next</Link></nav></section>
    <p v-if="!applications.data.length" class="rounded-xl bg-white p-8">No submitted applications yet.</p>
    <div class="space-y-6"><PartnerReview v-for="application in applications.data" :key="application.id" :application="application" :questionnaire="questionnaire" :label="types[application.type]?.label || 'Customer (legacy application)'"/></div>
    <nav aria-label="Pagination" class="mt-6 flex gap-4"><Link v-if="applications.prev_page_url" :href="applications.prev_page_url">Previous</Link><span>Page {{ applications.current_page }} of {{ applications.last_page }}</span><Link v-if="applications.next_page_url" :href="applications.next_page_url">Next</Link></nav>
</PortalLayout></template>
