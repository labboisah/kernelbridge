<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import NavigationIcon from '@/Components/NavigationIcon.vue';

const page = usePage();
const partnerApplications = computed(() => page.props.auth.partner_applications || []);
const groups = [
    { label: 'Workspace', icon: 'overview', links: [['overview', 'partner.dashboard', 'Overview', 'partner.dashboard']] },
    { label: 'Onboarding', icon: 'customers', links: [['pcr', 'partner.pcr.index', 'PCR register', 'partner.pcr.*'], ['leads', 'partner.crm.index', 'CRM pipeline', 'partner.crm.*'], ['customers', 'partner.customers', 'Clients', 'partner.customers*'], ['quotations', 'partner.quotations.index', 'Quotation', 'partner.quotations.*']] },
    { label: 'Management', icon: 'customers', links: [['subscriptions', 'partner.subscriptions', 'Subscription', 'partner.subscriptions'], ['payments', 'partner.payments', 'Payment', 'partner.payments'], ['invoices', 'partner.invoices', 'Invoice', 'partner.invoices*'], ['licenses', 'partner.activation-codes', 'Activation code', 'partner.activation-codes']] },
    { label: 'Finance', icon: 'payments', links: [['commission', 'partner.commission', 'Commission', 'partner.commission'], ['nrs-commission', 'partner.nrs-commission', 'NRS Commission', 'partner.nrs-commission'], ['effort-points', 'partner.effort-points', 'Effort Points', 'partner.effort-points']] },
    { label: 'Account', icon: 'profile', links: [['profile', 'profile.edit', 'Login & profile', 'profile.*']] },
];

const linksForGroup = group => {
    if (group.label !== 'Account') return group.links;

    return [
        ...partnerApplications.value.map(application => [
            `application-${application.type}`,
            'partners.onboarding',
            partnerApplications.value.length === 1 ? 'My application' : `My ${application.label.toLowerCase()} application`,
            'partners.onboarding',
            { type: application.type },
        ]),
        ...(page.props.auth.approved_agent && page.props.auth.partner_category === 'individual'
            ? [['organization-upgrade', 'partner.organization-upgrade', 'Upgrade to organization', 'partner.organization-upgrade']]
            : []),
        ...(page.props.auth.approved_agent ? [['bank-details', 'partner.bank.edit', 'Bank details', 'partner.bank.*']] : []),
        ...group.links,
    ];
};
const visibleLinks = group => linksForGroup(group).filter(link =>
    ['overview', 'profile'].includes(link[0]) || link[1] === 'partners.onboarding' || page.props.auth.approved_agent,
);
const isActive = link => route().current(link[3], link[4]);
const isGroupActive = group => visibleLinks(group).some(isActive);
</script>

<template>
    <details
        v-for="group in groups"
        v-show="visibleLinks(group).length"
        :key="group.label"
        class="group mb-2 overflow-hidden rounded-xl last:mb-0"
        :open="isGroupActive(group)"
    >
        <summary class="flex cursor-pointer list-none items-center gap-3 rounded-xl px-3 py-3 text-sm font-bold text-slate-700 transition hover:bg-slate-100 [&::-webkit-details-marker]:hidden">
            <NavigationIcon :name="group.icon" class="text-slate-500" />
            <span class="flex-1">{{ group.label }}</span>
            <svg class="h-4 w-4 text-slate-400 transition-transform group-open:rotate-180" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                <path d="m5 7.5 5 5 5-5" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
        </summary>
        <div class="space-y-1 pb-2 pl-3 pr-2">
            <Link
                v-for="link in visibleLinks(group)"
                :key="link[0]"
                :href="route(link[1], link[4])"
                class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm transition"
                :class="isActive(link) ? 'bg-[#0B3D6E] font-semibold text-white shadow-sm' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900'"
            >
                <NavigationIcon :name="link[0]" :class="isActive(link) ? 'text-cyan-300' : 'text-slate-400'" />
                {{ link[2] }}
            </Link>
        </div>
    </details>
</template>
