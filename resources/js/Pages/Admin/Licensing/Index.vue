<script setup>
import AdminNavigation from '@/Components/Admin/AdminNavigation.vue';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive } from 'vue';

const props = defineProps({
    licenses: { type: Object, required: true },
    filters: { type: Object, required: true },
});
const filters = reactive({ search: props.filters.search || '', status: props.filters.status || '' });
const applyFilters = () => router.get(route('admin.licenses.index'), filters, { preserveState: true, replace: true });
const resetFilters = () => router.get(route('admin.licenses.index'));
</script>

<template>
    <Head title="Licensing Codes" />
    <PortalLayout area="Administration">
        <template #navigation><AdminNavigation active="licenses" /></template>

        <div class="mb-6">
            <p class="text-sm font-semibold uppercase tracking-wider text-cyan-700">License operations</p>
            <h1 class="mt-2 text-3xl font-bold text-slate-900">Activation and installation codes</h1>
            <p class="mt-2 text-slate-600">Find a product license to issue one-time codes, revoke unused codes, or revoke active installations.</p>
        </div>

        <form class="mb-6 grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm md:grid-cols-[1fr_220px_auto]" @submit.prevent="applyFilters">
            <label>
                <span class="sr-only">Search licenses</span>
                <input v-model="filters.search" class="w-full" placeholder="License UUID, product, customer or email">
            </label>
            <label>
                <span class="sr-only">License status</span>
                <select v-model="filters.status" class="w-full">
                    <option value="">All license statuses</option>
                    <option value="active">Active</option>
                    <option value="suspended">Suspended</option>
                    <option value="expired">Expired</option>
                    <option value="revoked">Revoked</option>
                </select>
            </label>
            <div class="flex gap-2">
                <button class="form-button !px-4 !py-2">Filter</button>
                <button type="button" class="rounded-xl border border-slate-300 px-3 font-bold" @click="resetFilters">Clear</button>
            </div>
        </form>

        <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="hidden grid-cols-[1.4fr_1.2fr_.8fr_.8fr_auto] gap-4 border-b bg-slate-50 px-5 py-3 text-xs font-bold uppercase text-slate-500 lg:grid">
                <span>Product license</span><span>Customer</span><span>Plan / mode</span><span>Status</span><span></span>
            </div>
            <div v-for="license in licenses.data" :key="license.uuid" class="grid gap-3 border-b border-slate-100 p-5 last:border-0 lg:grid-cols-[1.4fr_1.2fr_.8fr_.8fr_auto] lg:items-center">
                <div>
                    <Link :href="route('admin.licenses.show', license.uuid)" class="font-bold text-[#0B3D6E] hover:text-cyan-700">{{ license.product.name }} <span class="text-xs text-slate-500">({{ license.product.code }})</span></Link>
                    <p class="mt-1 break-all font-mono text-xs text-slate-500">{{ license.uuid }}</p>
                </div>
                <div><p class="font-semibold">{{ license.customer.name }}</p><p class="text-sm text-slate-500">{{ license.customer.email || 'No email' }}</p></div>
                <div><p class="font-semibold">{{ license.plan || 'No plan' }}</p><p class="text-xs uppercase text-slate-500">{{ license.mode.replaceAll('_', ' ') }}</p></div>
                <span class="w-fit rounded-full bg-slate-100 px-3 py-1 text-xs font-bold uppercase">{{ license.status }}</span>
                <Link :href="route('admin.licenses.show', license.uuid)" class="rounded-lg border border-slate-300 px-4 py-2 text-center text-sm font-bold">Manage</Link>
            </div>
            <div v-if="!licenses.data.length" class="p-10 text-center text-slate-500">No licenses match these filters.</div>
        </div>

        <nav v-if="licenses.last_page > 1" class="mt-5 flex flex-wrap gap-2" aria-label="Pagination">
            <Link v-for="link in licenses.links" :key="link.label" :href="link.url || '#'" preserve-scroll class="rounded-lg border px-3 py-2 text-sm" :class="[link.active ? 'border-cyan-600 bg-cyan-50 text-cyan-800' : 'border-slate-300', !link.url ? 'pointer-events-none opacity-40' : '']" v-html="link.label" />
        </nav>
    </PortalLayout>
</template>
