<script setup>
import AccessControlLayout from '@/Components/Admin/AccessControl/AccessControlLayout.vue';
import ActionIcon from '@/Components/Admin/AccessControl/ActionIcon.vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

const props = defineProps({ users: Object, filters: Object, permissionGroups: Array });
const filter = reactive({ search: props.filters.search || '' });
const editing = ref(null);
const open = ref(false);
const permissionSearch = ref('');
const expiries = ref({});
const form = useForm({ grants: [] });
const filteredGroups = computed(() => props.permissionGroups.map(group => ({
    ...group,
    permissions: group.permissions.filter(permission =>
        permission.name.toLowerCase().includes(permissionSearch.value.toLowerCase()),
    ),
})).filter(group => group.permissions.length));
const title = value => value.replaceAll('_', ' ').replaceAll('.', ' / ').replace(/\b\w/g, character => character.toUpperCase());
const applyFilters = () => router.get(route('admin.access-control.user-permissions'), filter, { preserveState: true, replace: true });

const localInputDate = value => {
    const date = new Date(value);
    date.setMinutes(date.getMinutes() - date.getTimezoneOffset());
    return date.toISOString().slice(0, 16);
};
const defaultExpiry = () => localInputDate(Date.now() + 24 * 60 * 60 * 1000);
const isSelected = id => Object.prototype.hasOwnProperty.call(expiries.value, id);
const activeGrants = user => user.temporary_permission_grants.filter(grant => new Date(grant.expires_at).getTime() > Date.now());
const expiredGrants = user => user.temporary_permission_grants.filter(grant => new Date(grant.expires_at).getTime() <= Date.now());
const groupChecked = group => group.permissions.length > 0 && group.permissions.every(permission => isSelected(permission.id));
const groupIndeterminate = group => group.permissions.some(permission => isSelected(permission.id)) && !groupChecked(group);

const manageUser = user => {
    editing.value = user;
    expiries.value = Object.fromEntries(activeGrants(user).map(grant => [
        grant.permission_id,
        localInputDate(grant.expires_at),
    ]));
    permissionSearch.value = '';
    form.clearErrors();
    open.value = true;
};

const close = () => {
    open.value = false;
    editing.value = null;
    expiries.value = {};
    form.reset();
    form.clearErrors();
};

const togglePermission = permission => {
    if (isSelected(permission.id)) {
        const next = { ...expiries.value };
        delete next[permission.id];
        expiries.value = next;
    } else {
        expiries.value = { ...expiries.value, [permission.id]: defaultExpiry() };
    }
};

const toggleGroup = group => {
    const next = { ...expiries.value };
    if (groupChecked(group)) {
        group.permissions.forEach(permission => delete next[permission.id]);
    } else {
        group.permissions.forEach(permission => {
            if (!Object.prototype.hasOwnProperty.call(next, permission.id)) {
                next[permission.id] = defaultExpiry();
            }
        });
    }
    expiries.value = next;
};

const save = () => {
    form.grants = Object.entries(expiries.value).map(([permissionId, expiresAt]) => ({
        permission_id: Number(permissionId),
        expires_at: expiresAt,
    }));
    form.put(route('admin.access-control.user-permissions.update', editing.value.public_id), {
        preserveScroll: true,
        onSuccess: close,
    });
};

const revokeAll = user => {
    if (window.confirm(`Revoke all temporary permissions for ${user.name}?`)) {
        router.delete(route('admin.access-control.user-permissions.destroy', user.public_id), { preserveScroll: true });
    }
};
</script>

<template>
    <AccessControlLayout active="user-permissions" title="User Permission (temporary)" description="Grant individual users temporary permissions with an expiry for each permission. Expired grants no longer authorize actions; role-based permissions remain unaffected.">
        <form class="mt-6 flex flex-wrap gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm" @submit.prevent="applyFilters">
            <label class="sr-only" for="temporary-user-search">Search users</label>
            <input id="temporary-user-search" v-model="filter.search" type="search" class="min-w-[220px] flex-1" placeholder="Search user name or email" />
            <button class="rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50">
                <span class="inline-flex items-center gap-2"><ActionIcon name="search" /> Search users</span>
            </button>
        </form>

        <div class="mt-5 overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
            <table class="w-full min-w-[900px] text-left">
                <thead class="border-b bg-slate-50 text-xs font-bold uppercase text-slate-500">
                    <tr><th class="px-5 py-3">User</th><th class="px-5 py-3">Account roles</th><th class="px-5 py-3">Temporary grants</th><th class="px-5 py-3 text-right">Actions</th></tr>
                </thead>
                <tbody>
                    <tr v-for="user in users.data" :key="user.public_id" class="border-b last:border-0">
                        <td class="px-5 py-4">
                            <strong class="text-[#0B3D6E]">{{ user.name }}</strong>
                            <p class="text-sm text-slate-500">{{ user.email }} · <span class="capitalize">{{ user.status || 'unknown' }}</span></p>
                        </td>
                        <td class="px-5 py-4">
                            <span v-for="role in user.roles" :key="role.id" class="mb-1 mr-1 inline-block rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold">{{ title(role.name) }}</span>
                            <span v-if="!user.roles.length" class="text-sm text-slate-400">No roles</span>
                        </td>
                        <td class="px-5 py-4">
                            <p class="text-sm font-semibold text-emerald-700">{{ activeGrants(user).length }} active</p>
                            <p class="text-xs text-slate-500">{{ expiredGrants(user).length }} expired</p>
                            <p class="mt-1 max-w-sm truncate text-xs text-slate-500">{{ activeGrants(user).map(grant => grant.permission.name).join(', ') || 'No active grants' }}</p>
                        </td>
                        <td class="px-5 py-4">
                            <div class="flex justify-end gap-2">
                                <button type="button" class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-2 text-sm font-bold text-slate-700 hover:bg-slate-50" @click="manageUser(user)">
                                    <ActionIcon :name="user.temporary_permission_grants.length ? 'edit' : 'create'" />
                                    {{ user.temporary_permission_grants.length ? 'Manage' : 'Grant' }}
                                </button>
                                <button v-if="user.temporary_permission_grants.length" type="button" class="inline-flex items-center gap-1.5 rounded-lg border border-red-200 px-3 py-2 text-sm font-bold text-red-700 hover:bg-red-50" @click="revokeAll(user)">
                                    <ActionIcon name="delete" /> Revoke all
                                </button>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!users.data.length"><td colspan="4" class="p-10 text-center text-slate-500">No users match this search.</td></tr>
                </tbody>
            </table>
        </div>

        <nav v-if="users.last_page > 1" class="mt-5 flex flex-wrap gap-2" aria-label="Temporary permission pages">
            <Link v-for="link in users.links" :key="link.label" :href="link.url || '#'" class="rounded-lg border px-3 py-2 text-sm" :class="[link.active ? 'bg-[#0B3D6E] text-white' : '', !link.url ? 'pointer-events-none opacity-40' : '']" v-html="link.label" />
        </nav>

        <div v-if="open" class="fixed inset-0 z-50 grid place-items-center overflow-y-auto bg-slate-950/50 p-4" role="dialog" aria-modal="true" @click.self="close">
            <form class="my-8 w-full max-w-4xl rounded-2xl bg-white p-6 shadow-2xl" @submit.prevent="save">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <h2 class="text-xl font-bold">Temporary permissions</h2>
                        <p class="mt-1 text-sm text-slate-500">{{ editing?.name }} · {{ editing?.email }}</p>
                    </div>
                    <button type="button" aria-label="Close dialog" class="text-2xl text-slate-500" @click="close">&times;</button>
                </div>
                <p class="mt-3 text-sm text-slate-600">Check permissions to grant them. Each checked permission has its own expiration date and time.</p>
                <label class="sr-only" for="temporary-permission-search">Search permissions</label>
                <input id="temporary-permission-search" v-model="permissionSearch" type="search" class="mt-4 w-full rounded-xl border-slate-300" placeholder="Search permissions" />
                <div class="mt-4 grid max-h-[55vh] gap-4 overflow-y-auto lg:grid-cols-2">
                    <section v-for="group in filteredGroups" :key="group.label" class="rounded-xl border border-slate-200 p-4">
                        <label class="flex items-center gap-3 border-b border-slate-100 pb-3 font-bold text-[#0B3D6E]">
                            <input type="checkbox" :checked="groupChecked(group)" :indeterminate="groupIndeterminate(group)" :aria-label="`Select all ${group.label} permissions`" @change="toggleGroup(group)" />
                            {{ group.label }}
                        </label>
                        <div class="mt-2 space-y-3">
                            <div v-for="permission in group.permissions" :key="permission.id" class="rounded-lg border-b border-slate-100 pb-3 last:border-0">
                                <label class="flex cursor-pointer items-center gap-3 py-1">
                                    <input type="checkbox" :checked="isSelected(permission.id)" @change="togglePermission(permission)" />
                                    <span class="text-sm font-semibold text-slate-700">{{ title(permission.name) }}</span>
                                </label>
                                <label v-if="isSelected(permission.id)" class="ml-7 mt-1 block text-xs font-semibold text-slate-500">
                                    Expires
                                    <input v-model="expiries[permission.id]" type="datetime-local" required class="mt-1 block w-full rounded-lg border-slate-300 text-sm" />
                                </label>
                            </div>
                        </div>
                    </section>
                </div>
                <p v-if="!filteredGroups.length" class="mt-4 text-sm text-slate-500">No permissions match this search.</p>
                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" class="rounded-xl border px-5 py-3 font-bold" @click="close">Cancel</button>
                    <button class="form-button inline-flex items-center gap-2" :disabled="form.processing"><ActionIcon name="save" /> {{ form.processing ? 'Saving...' : 'Save grants' }}</button>
                </div>
            </form>
        </div>
    </AccessControlLayout>
</template>
