<script setup>
import { Link } from '@inertiajs/vue3';
import NavigationIcon from '@/Components/NavigationIcon.vue';
const props = defineProps({ active: { type: String, default: null } });
const adminRoles = ['super_admin','admin','sales','finance','support'];
const links = [['overview','dashboard','Dashboard','dashboard'],['services','portal.services.index','My services','portal.services.*'],['licenses','portal.licenses.index','Licensing','portal.licenses.*'],['invoices','portal.invoices.index','Invoices','portal.invoices.*'],['payments','portal.payments.index','Payments','portal.payments.*'],['support','support.index','Support','support.*'],['partners','partners.index','Partner onboarding','partners.*'],['profile','profile.edit','Profile','profile.*']];
const isActive = (link) => props.active ? props.active === link[0] : route().current(link[3]);
</script>
<template><p class="mb-2 px-3 text-[10px] font-bold uppercase tracking-[.16em] text-slate-400">Customer portal</p><Link v-for="link in links" :key="link[0]" :href="route(link[1])" class="mb-1 flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm transition" :class="isActive(link)?'bg-[#0B3D6E] font-semibold text-white':'text-slate-600 hover:bg-slate-100 hover:text-slate-900'"><NavigationIcon :name="link[0]" :class="isActive(link) ? 'text-cyan-300' : 'text-slate-400'"/>{{ link[2] }}</Link><Link v-if="$page.props.auth.roles.some(role=>adminRoles.includes(role))" :href="route('admin.dashboard')" class="mt-5 flex items-center gap-3 rounded-xl border border-cyan-200 bg-cyan-50 px-3 py-2.5 text-sm font-semibold text-cyan-800"><NavigationIcon name="overview"/>Administration</Link></template>
