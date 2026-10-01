<script setup>
import { Link } from '@inertiajs/vue3';
import NavigationIcon from '@/Components/NavigationIcon.vue';
const props = defineProps({ active: { type: String, default: null } });
const groups = [
    { label: 'Workspace', links: [['overview','admin.dashboard','Overview','admin.dashboard'],['staff','admin.staff.index','Staff','admin.staff.*'],['access-control','admin.access-control.index','Access Control','admin.access-control.*']] },
    { label: 'Sales & customers', links: [['leads','admin.leads.index','CRM Pipeline','admin.leads.*'],['partners','admin.partners.index','Partners','admin.partners.*'],['customers','admin.customers.index','Customers','admin.customers.*'],['quotations','admin.quotations.index','Quotations','admin.quotations.*']] },
    { label: 'Finance', links: [['commissions','admin.partners.commissions','Commission settings','admin.partners.commissions*'],['billing','admin.billing.edit','VAT settings','admin.billing.*'],['invoices','admin.invoices.index','Invoices','admin.invoices.*'],['payments','admin.payments.index','Payments','admin.payments.*']] },
    { label: 'Operations', links: [['support','admin.support.index','Support','admin.support.*'],['support-teams','admin.support-teams.index','Support Teams','admin.support-teams.*'],['demo-mode','admin.demo-mode.index','Demo Mode','admin.demo-mode.*'],['mcp','admin.mcp.index','MCP Operators','admin.mcp.*']] },
    { label: 'Catalogue', links: [['products','admin.products.index','Products','admin.products.*'],['plans','admin.products.index','Plans & pricing','admin.plans.*'],['hosting','admin.hosting.edit','Online implementation','admin.hosting.*']] },
];
const isActive = (link) => {
    if (link[0] === 'partners' && route().current('admin.partners.commissions*')) return false;
    return props.active ? props.active === link[0] : route().current(link[3]);
};
</script>
<template><div v-for="group in groups" :key="group.label" class="mb-5 last:mb-0"><p class="mb-2 px-3 text-[10px] font-bold uppercase tracking-[.16em] text-slate-400">{{ group.label }}</p><Link v-for="link in group.links" :key="link[0]" :href="route(link[1])" class="mb-1 flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm transition" :class="isActive(link)?'bg-[#0B3D6E] font-semibold text-white shadow-sm':'text-slate-600 hover:bg-slate-100 hover:text-slate-900'"><NavigationIcon :name="link[0]" :class="isActive(link) ? 'text-cyan-300' : 'text-slate-400'"/>{{ link[2] }}</Link></div></template>

