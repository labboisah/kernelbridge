<script setup>
defineProps({
    name: { type: String, required: true },
});
</script>

<template>
    <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <template v-if="name === 'overview'"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></template>
        <template v-else-if="name === 'leads'"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="m16 11 2 2 4-4"/></template>
        <template v-else-if="name === 'customers'"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></template>
        <template v-else-if="name === 'staff'"><circle cx="9" cy="7" r="4"/><path d="M3 21v-2a6 6 0 0 1 12 0v2M16 11h6M19 8v6"/></template>
        <template v-else-if="name === 'access-control'"><rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3M12 14v3"/></template>
        <template v-else-if="name === 'quotations'"><path d="M6 2h9l5 5v15H6z"/><path d="M14 2v6h6M9 13h8M9 17h6"/></template>
        <template v-else-if="name === 'invoices'"><path d="M5 2h14v20l-3-2-4 2-4-2-3 2z"/><path d="M9 8h6M9 12h6M9 16h4"/></template>
        <template v-else-if="name === 'payments'"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20M6 15h2"/></template>
        <template v-else-if="name === 'support'"><path d="M4 14a8 8 0 0 1 16 0"/><path d="M18 19c0 1.7-1.3 3-3 3h-3"/><rect x="2" y="13" width="4" height="6" rx="2"/><rect x="18" y="13" width="4" height="6" rx="2"/></template>
        <template v-else-if="name === 'support-teams'"><circle cx="8" cy="8" r="3"/><circle cx="17" cy="9" r="2.5"/><path d="M2 21a6 6 0 0 1 12 0M13 17a5 5 0 0 1 9 3"/></template>
        <template v-else-if="name === 'demo-mode'"><path d="M8 3h8l1 4H7z"/><rect x="4" y="7" width="16" height="14" rx="2"/><path d="m10 12 5 3-5 3z"/></template>
        <template v-else-if="name === 'products'"><path d="m12 2 9 5-9 5-9-5z"/><path d="m3 12 9 5 9-5M3 17l9 5 9-5"/></template>
        <template v-else-if="name === 'plans'"><path d="M4 5h16M4 12h16M4 19h16"/><circle cx="8" cy="5" r="2" fill="currentColor" stroke="none"/><circle cx="16" cy="12" r="2" fill="currentColor" stroke="none"/><circle cx="10" cy="19" r="2" fill="currentColor" stroke="none"/></template>
        <template v-else-if="name === 'services'"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M8 4V2M16 4V2M8 10h8M8 14h5"/></template>
        <template v-else-if="name === 'licenses'"><circle cx="8" cy="15" r="4"/><path d="m11 12 8-8 2 2-2 2 1.5 1.5-2 2L17 10l-3 3"/></template>
        <template v-else-if="name === 'profile'"><circle cx="12" cy="8" r="4"/><path d="M4 22a8 8 0 0 1 16 0"/></template>
        <template v-else><circle cx="12" cy="12" r="9"/><path d="M9 12h6"/></template>
    </svg>
</template>
