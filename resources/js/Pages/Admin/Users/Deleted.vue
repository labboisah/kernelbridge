<script setup>
import AdminNavigation from '@/Components/Admin/AdminNavigation.vue';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

defineProps({ users: Object });

const page = usePage();
const pageError = computed(() => Object.values(page.props.errors || {}).flat().join(' '));
const title = value => value.replaceAll('_', ' ').replace(/\b\w/g, character => character.toUpperCase());

const restoreAccount = user => {
    const access = user.status === 'active' ? 'active and able to sign in' : 'suspended';
    if (window.confirm(`Restore ${user.name}'s account? It will be ${access}.`)) {
        router.post(route('admin.users.restore', user.public_id), {}, { preserveScroll: true });
    }
};
</script>

<template>
    <Head title="Deleted accounts" />
    <PortalLayout area="Administration">
        <template #navigation><AdminNavigation active="users" /></template>
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="text-sm font-bold uppercase text-cyan-700">Access control</p>
                <h1 class="mt-1 text-3xl font-bold">Deleted accounts</h1>
                <p class="mt-2 text-slate-600">Restore an account to its previous roles and access status.</p>
            </div>
            <Link :href="route('admin.users.index')" class="rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50">
                Active accounts
            </Link>
        </div>

        <p v-if="$page.props.flash.success" role="status" class="success-message mt-5">{{ $page.props.flash.success }}</p>
        <p v-if="pageError" role="alert" class="mt-5 rounded-xl bg-red-50 p-4 text-sm font-semibold text-red-800">{{ pageError }}</p>

        <div class="mt-6 overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
            <table class="w-full min-w-[800px] text-left">
                <thead class="border-b bg-slate-50 text-xs font-bold uppercase text-slate-500">
                    <tr>
                        <th class="px-5 py-3">User</th>
                        <th class="px-5 py-3">Roles</th>
                        <th class="px-5 py-3">Status</th>
                        <th class="px-5 py-3">Deleted</th>
                        <th class="px-5 py-3 text-right">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="user in users.data" :key="user.public_id" class="border-b last:border-0">
                        <td class="px-5 py-4">
                            <strong class="text-[#0B3D6E]">{{ user.name }}</strong>
                            <p class="text-sm text-slate-500">{{ user.email }}</p>
                        </td>
                        <td class="px-5 py-4">
                            <span v-for="role in user.roles" :key="role.name" class="mb-1 mr-1 inline-block rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold">{{ title(role.name) }}</span>
                            <span v-if="!user.roles.length" class="text-sm text-slate-400">No roles</span>
                        </td>
                        <td class="px-5 py-4">
                            <span class="rounded-full px-2.5 py-1 text-xs font-bold uppercase" :class="user.status === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'">{{ user.status }}</span>
                        </td>
                        <td class="px-5 py-4 text-sm text-slate-500">{{ new Date(user.deleted_at).toLocaleString() }}</td>
                        <td class="px-5 py-4 text-right">
                            <button type="button" class="rounded-lg border border-emerald-200 px-3 py-2 text-sm font-bold text-emerald-700 hover:bg-emerald-50" @click="restoreAccount(user)">
                                Restore
                            </button>
                        </td>
                    </tr>
                    <tr v-if="!users.data.length">
                        <td colspan="5" class="p-10 text-center text-slate-500">There are no deleted accounts to restore.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <nav v-if="users.last_page > 1" class="mt-5 flex flex-wrap gap-2" aria-label="Deleted account pages">
            <Link
                v-for="link in users.links"
                :key="link.label"
                :href="link.url || '#'"
                class="rounded-lg border px-3 py-2 text-sm"
                :class="[link.active ? 'bg-[#0B3D6E] text-white' : '', !link.url ? 'pointer-events-none opacity-40' : '']"
                v-html="link.label"
            />
        </nav>
    </PortalLayout>
</template>
