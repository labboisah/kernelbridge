<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import NavigationIcon from '@/Components/NavigationIcon.vue';

const page = usePage();
const links = [
    ['overview', 'partner.dashboard', 'Overview', 'partner.dashboard'],
    ['leads', 'partner.crm.index', 'CRM pipeline', 'partner.crm.*'],
    ['customers', 'partner.customers', 'Clients / customers', 'partner.customers*'],
    ['support', 'partner.support', 'Support requests', 'partner.support*'],
    ['plans', 'partner.revenue', 'Revenue & commissions', 'partner.revenue*'],
    ['invoices', 'partner.invoices', 'Invoices', 'partner.invoices*'],
    ['payments', 'partner.payments', 'Payments', 'partner.payments*'],
];

const visibleLinks = () => links.filter(link => link[0] === 'overview' || page.props.auth.approved_agent);
const isActive = link => route().current(link[3]);
</script>

<template>
    <p class="mb-2 px-3 text-[10px] font-bold uppercase tracking-[.16em] text-slate-400">Partner portal</p>
    <Link
        v-for="link in visibleLinks()"
        :key="link[0]"
        :href="route(link[1])"
        class="mb-1 flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm transition"
        :class="isActive(link) ? 'bg-[#0B3D6E] font-semibold text-white' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900'"
    >
        <NavigationIcon :name="link[0]" :class="isActive(link) ? 'text-cyan-300' : 'text-slate-400'" />
        {{ link[2] }}
    </Link>
    <Link
        :href="route('profile.edit')"
        class="mb-1 flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm transition"
        :class="route().current('profile.*') ? 'bg-[#0B3D6E] font-semibold text-white' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900'"
    >
        <NavigationIcon name="profile" :class="route().current('profile.*') ? 'text-cyan-300' : 'text-slate-400'" />
        Login & profile
    </Link>
</template>
