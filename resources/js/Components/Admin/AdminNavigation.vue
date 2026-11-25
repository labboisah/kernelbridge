<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import NavigationIcon from '@/Components/NavigationIcon.vue';

const props = defineProps({ active: { type: String, default: null } });
const page = usePage();
const groups = [
    { label: 'Workspace', icon: 'overview', links: [['overview', 'admin.dashboard', 'Overview', 'admin.dashboard', 'admin.dashboard'], ['staff', 'admin.staff.index', 'Staff', 'admin.staff.*', 'staff.manage'], ['users', 'admin.users.index', 'User accounts', 'admin.users.*', 'users.manage']] },
    { label: 'Access Control', icon: 'access-control', links: [['access-roles', 'admin.access-control.roles', 'Roles', 'admin.access-control.roles', 'access.manage'], ['access-permissions', 'admin.access-control.permissions', 'Permissions', 'admin.access-control.permissions', 'access.manage'], ['role-permissions', 'admin.access-control.role-permissions', 'Role Permissions', 'admin.access-control.role-permissions*', 'access.manage'], ['user-roles', 'admin.access-control.user-roles', 'User Roles', 'admin.access-control.user-roles*', 'access.manage'], ['user-permissions', 'admin.access-control.user-permissions', 'User Permission (temporary)', 'admin.access-control.user-permissions*', 'access.manage']] },
    { label: 'Sales & customers', icon: 'customers', links: [['leads', 'admin.leads.index', 'CRM Pipeline', 'admin.leads.*', 'lead.view'], ['partners', 'admin.partners.index', 'Partners', 'admin.partners.*', 'partner.manage'], ['customers', 'admin.customers.index', 'Customers', 'admin.customers.*', 'customer.view'], ['quotations', 'admin.quotations.index', 'Quotations', 'admin.quotations.*', 'quotation.view']] },
    { label: 'Finance', icon: 'payments', links: [['commissions', 'admin.partners.commissions', 'Commission settings', 'admin.partners.commissions*', 'partner.commission.manage'], ['billing', 'admin.billing.edit', 'VAT settings', 'admin.billing.*', 'billing.manage'], ['invoices', 'admin.invoices.index', 'Invoices', 'admin.invoices.*', 'invoice.view'], ['payments', 'admin.payments.index', 'Payments', 'admin.payments.*', 'payment.view']] },
    { label: 'Operations', icon: 'operations', links: [['notifications', 'admin.notifications.index', 'Notifications', 'admin.notifications.*', 'notification.manage'], ['licenses', 'admin.licenses.index', 'Licensing Codes', 'admin.licenses.*', 'license.manage'], ['mcp', 'admin.mcp.index', 'Integration Credentials', 'admin.mcp.*', 'mcp.manage']] },
    { label: 'Support', icon: 'support', links: [['support', 'admin.support.index', 'Support', 'admin.support.*', 'support.view'], ['support-teams', 'admin.support-teams.index', 'Support Teams', 'admin.support-teams.*', 'support.manage']] },
    { label: 'Catalogue', icon: 'products', links: [['products', 'admin.products.index', 'Products', 'admin.products.*', 'product.view'], ['plans', 'admin.products.index', 'Plans & pricing', 'admin.plans.*', 'plan.view'], ['hosting', 'admin.hosting.edit', 'Online implementation', 'admin.hosting.*', 'hosting.manage']] },
];

const visibleLinks = group => {
    return group.links.filter(link =>
        page.props.auth.permissions?.includes(link[4]),
    );
};
const isActive = link => {
    if (link[0] === 'partners' && route().current('admin.partners.commissions*')) return false;
    return props.active ? props.active === link[0] : route().current(link[3]);
};
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
                :href="route(link[1])"
                class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm transition"
                :class="isActive(link) ? 'bg-[#0B3D6E] font-semibold text-white shadow-sm' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900'"
            >
                <NavigationIcon :name="link[0]" :class="isActive(link) ? 'text-cyan-300' : 'text-slate-400'" />
                {{ link[2] }}
            </Link>
        </div>
    </details>
</template>
