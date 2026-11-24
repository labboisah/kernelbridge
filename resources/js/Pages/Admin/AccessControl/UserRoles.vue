<script setup>
import AccessControlLayout from '@/Components/Admin/AccessControl/AccessControlLayout.vue';
import ActionIcon from '@/Components/Admin/AccessControl/ActionIcon.vue';
import InputError from '@/Components/InputError.vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';

const props = defineProps({ users: Object, filters: Object, roleGroups: Array, currentUserPublicId: String });
const filter = reactive({ search: props.filters.search || '' });
const editing = ref(null);
const open = ref(false);
const form = useForm({ role_ids: [] });
const title = value => value.replaceAll('_', ' ').replace(/\b\w/g, character => character.toUpperCase());
const applyFilters = () => router.get(route('admin.access-control.user-roles'), filter, { preserveState: true, replace: true });

const editUser = user => {
    editing.value = user;
    form.role_ids = user.roles.map(role => role.id);
    form.clearErrors();
    open.value = true;
};

const close = () => {
    open.value = false;
    editing.value = null;
    form.reset();
    form.clearErrors();
};

const requiredRole = role => editing.value?.public_id === props.currentUserPublicId && role.name === 'super_admin';
const groupChecked = group => group.roles.length > 0 && group.roles.every(role => form.role_ids.includes(role.id));
const groupIndeterminate = group => group.roles.some(role => form.role_ids.includes(role.id)) && !groupChecked(group);

const toggleGroup = group => {
    const ids = group.roles.filter(role => !requiredRole(role)).map(role => role.id);
    form.role_ids = groupChecked(group)
        ? form.role_ids.filter(id => !ids.includes(id))
        : [...new Set([...form.role_ids, ...ids])];
};

const toggleRole = role => {
    if (requiredRole(role)) return;
    form.role_ids = form.role_ids.includes(role.id)
        ? form.role_ids.filter(id => id !== role.id)
        : [...form.role_ids, role.id];
};

const save = () => form.put(route('admin.access-control.user-roles.update', editing.value.public_id), {
    preserveScroll: true,
    onSuccess: close,
});
</script>

<template>
    <AccessControlLayout active="user-roles" title="User Roles" description="Search user accounts and manage their role memberships using role-group checkboxes.">
        <form class="mt-6 flex flex-wrap gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm" @submit.prevent="applyFilters">
            <label class="sr-only" for="user-role-search">Search users</label>
            <input id="user-role-search" v-model="filter.search" type="search" class="min-w-[220px] flex-1" placeholder="Search user name or email" />
            <button class="rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50">
                <span class="inline-flex items-center gap-2"><ActionIcon name="search" /> Search users</span>
            </button>
        </form>

        <div class="mt-5 overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
            <table class="w-full min-w-[760px] text-left">
                <thead class="border-b bg-slate-50 text-xs font-bold uppercase text-slate-500">
                    <tr><th class="px-5 py-3">User</th><th class="px-5 py-3">Status</th><th class="px-5 py-3">Roles</th><th class="px-5 py-3 text-right">Action</th></tr>
                </thead>
                <tbody>
                    <tr v-for="user in users.data" :key="user.public_id" class="border-b last:border-0">
                        <td class="px-5 py-4">
                            <strong class="text-[#0B3D6E]">{{ user.name }}</strong>
                            <p class="text-sm text-slate-500">{{ user.email }}</p>
                        </td>
                        <td class="px-5 py-4 text-sm capitalize text-slate-600">{{ user.status || 'unknown' }}</td>
                        <td class="px-5 py-4">
                            <span v-for="role in user.roles" :key="role.id" class="mb-1 mr-1 inline-block rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold">{{ title(role.name) }}</span>
                            <span v-if="!user.roles.length" class="text-sm text-slate-400">No roles</span>
                        </td>
                        <td class="px-5 py-4 text-right">
                            <button type="button" class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-2 text-sm font-bold text-slate-700 hover:bg-slate-50" @click="editUser(user)">
                                <ActionIcon name="edit" /> Manage roles
                            </button>
                        </td>
                    </tr>
                    <tr v-if="!users.data.length"><td colspan="4" class="p-10 text-center text-slate-500">No users match this search.</td></tr>
                </tbody>
            </table>
        </div>

        <nav v-if="users.last_page > 1" class="mt-5 flex flex-wrap gap-2" aria-label="User role pages">
            <Link v-for="link in users.links" :key="link.label" :href="link.url || '#'" class="rounded-lg border px-3 py-2 text-sm" :class="[link.active ? 'bg-[#0B3D6E] text-white' : '', !link.url ? 'pointer-events-none opacity-40' : '']" v-html="link.label" />
        </nav>

        <div v-if="open" class="fixed inset-0 z-50 grid place-items-center overflow-y-auto bg-slate-950/50 p-4" role="dialog" aria-modal="true" @click.self="close">
            <form class="my-8 w-full max-w-3xl rounded-2xl bg-white p-6 shadow-2xl" @submit.prevent="save">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <h2 class="text-xl font-bold">Manage user roles</h2>
                        <p class="mt-1 text-sm text-slate-500">{{ editing?.name }} · {{ editing?.email }}</p>
                    </div>
                    <button type="button" aria-label="Close dialog" class="text-2xl text-slate-500" @click="close">&times;</button>
                </div>
                <p class="mt-4 text-sm text-slate-600">Select a group checkbox to add or clear its roles. A user must retain at least one role.</p>
                <div class="mt-5 grid gap-4 sm:grid-cols-2">
                    <section v-for="group in roleGroups" :key="group.label" class="rounded-xl border border-slate-200 p-4">
                        <label class="flex items-center gap-3 border-b border-slate-100 pb-3 font-bold text-[#0B3D6E]">
                            <input type="checkbox" :checked="groupChecked(group)" :indeterminate="groupIndeterminate(group)" :aria-label="`Select all ${group.label} roles`" @change="toggleGroup(group)" />
                            {{ group.label }}
                        </label>
                        <div class="mt-2 space-y-1">
                            <label v-for="role in group.roles" :key="role.id" class="flex items-center gap-3 rounded-lg px-2 py-2 hover:bg-slate-50" :class="requiredRole(role) ? 'cursor-not-allowed opacity-60' : 'cursor-pointer'">
                                <input type="checkbox" :checked="form.role_ids.includes(role.id)" :disabled="requiredRole(role)" @change="toggleRole(role)" />
                                <span class="text-sm font-semibold text-slate-700">{{ title(role.name) }}</span>
                                <span v-if="requiredRole(role)" class="ml-auto text-[11px] text-slate-400">Required</span>
                            </label>
                        </div>
                    </section>
                </div>
                <InputError :message="form.errors.role_ids" class="mt-3" />
                <InputError v-for="(_, index) in form.role_ids" :key="index" :message="form.errors[`role_ids.${index}`]" />
                <p v-if="editing?.status === 'active' && editing.roles.some(role => role.name === 'super_admin')" class="mt-3 text-xs text-amber-700">The last active super administrator cannot be demoted.</p>
                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" class="rounded-xl border px-5 py-3 font-bold" @click="close">Cancel</button>
                    <button class="form-button inline-flex items-center gap-2" :disabled="form.processing"><ActionIcon name="save" /> {{ form.processing ? 'Saving...' : 'Save roles' }}</button>
                </div>
            </form>
        </div>
    </AccessControlLayout>
</template>
