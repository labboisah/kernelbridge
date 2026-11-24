<script setup>
import AccessControlLayout from '@/Components/Admin/AccessControl/AccessControlLayout.vue';
import ActionIcon from '@/Components/Admin/AccessControl/ActionIcon.vue';
import InputError from '@/Components/InputError.vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';

const props = defineProps({ roles: Object, filters: Object });
const filter = reactive({ search: props.filters.search || '' });
const editing = ref(null);
const open = ref(false);
const form = useForm({ name: '' });
const title = value => value.replaceAll('_', ' ').replace(/\b\w/g, character => character.toUpperCase());
const applyFilters = () => router.get(route('admin.access-control.roles'), filter, { preserveState: true, replace: true });

const createRole = () => {
    editing.value = null;
    form.reset();
    form.clearErrors();
    open.value = true;
};

const editRole = role => {
    editing.value = role;
    form.name = role.name;
    form.clearErrors();
    open.value = true;
};

const close = () => {
    open.value = false;
    editing.value = null;
    form.reset();
    form.clearErrors();
};

const save = () => form[editing.value ? 'put' : 'post'](
    editing.value
        ? route('admin.access-control.roles.update', editing.value.id)
        : route('admin.access-control.roles.store'),
    { preserveScroll: true, onSuccess: close },
);

const deleteRole = role => {
    if (window.confirm(`Delete the ${title(role.name)} role? Users assigned this role will lose it.`)) {
        router.delete(route('admin.access-control.roles.destroy', role.id), { preserveScroll: true });
    }
};
</script>

<template>
    <AccessControlLayout active="access-roles" title="Roles" description="Create and maintain system roles. Built-in roles stay protected so application access rules remain intact.">
        <template #actions>
            <button type="button" class="form-button inline-flex items-center gap-2" @click="createRole">
                <ActionIcon name="create" /> Create role
            </button>
        </template>

        <form class="mt-6 flex flex-wrap gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm" @submit.prevent="applyFilters">
            <label class="sr-only" for="role-search">Search roles</label>
            <input id="role-search" v-model="filter.search" type="search" class="min-w-[220px] flex-1" placeholder="Search role name" />
            <button class="rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50">
                <span class="inline-flex items-center gap-2"><ActionIcon name="search" /> Search</span>
            </button>
        </form>

        <div class="mt-5 overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
            <table class="w-full min-w-[640px] text-left">
                <thead class="border-b bg-slate-50 text-xs font-bold uppercase text-slate-500">
                    <tr><th class="px-5 py-3">Role</th><th class="px-5 py-3">Type</th><th class="px-5 py-3 text-right">Actions</th></tr>
                </thead>
                <tbody>
                    <tr v-for="role in roles.data" :key="role.id" class="border-b last:border-0">
                        <td class="px-5 py-4 font-semibold text-[#0B3D6E]">{{ title(role.name) }} <span class="ml-1 text-xs font-normal text-slate-400">({{ role.name }})</span></td>
                        <td class="px-5 py-4 text-sm text-slate-600">{{ role.is_system ? 'System role' : 'Custom role' }}</td>
                        <td class="px-5 py-4">
                            <div class="flex flex-wrap justify-end gap-2">
                                <Link :href="route('admin.access-control.role-permissions', { role_id: role.id })" class="inline-flex items-center gap-1.5 rounded-lg border border-cyan-200 px-3 py-2 text-sm font-bold text-cyan-800 hover:bg-cyan-50">
                                    <ActionIcon name="edit" /> Permissions
                                </Link>
                                <template v-if="!role.is_system">
                                    <button type="button" class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-2 text-sm font-bold text-slate-700 hover:bg-slate-50" @click="editRole(role)">
                                        <ActionIcon name="edit" /> Edit
                                    </button>
                                    <button type="button" class="inline-flex items-center gap-1.5 rounded-lg border border-red-200 px-3 py-2 text-sm font-bold text-red-700 hover:bg-red-50" @click="deleteRole(role)">
                                        <ActionIcon name="delete" /> Delete
                                    </button>
                                </template>
                                <span v-else class="self-center text-xs text-slate-400">System role protected</span>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!roles.data.length"><td colspan="3" class="p-10 text-center text-slate-500">No roles match this search.</td></tr>
                </tbody>
            </table>
        </div>

        <nav v-if="roles.last_page > 1" class="mt-5 flex flex-wrap gap-2" aria-label="Role pages">
            <Link v-for="link in roles.links" :key="link.label" :href="link.url || '#'" class="rounded-lg border px-3 py-2 text-sm" :class="[link.active ? 'bg-[#0B3D6E] text-white' : '', !link.url ? 'pointer-events-none opacity-40' : '']" v-html="link.label" />
        </nav>

        <div v-if="open" class="fixed inset-0 z-50 grid place-items-center overflow-y-auto bg-slate-950/50 p-4" role="dialog" aria-modal="true" @click.self="close">
            <form class="my-8 w-full max-w-lg rounded-2xl bg-white p-6 shadow-2xl" @submit.prevent="save">
                <div class="flex items-center justify-between gap-4">
                    <h2 class="text-xl font-bold">{{ editing ? 'Edit role' : 'Create role' }}</h2>
                    <button type="button" aria-label="Close dialog" class="text-2xl text-slate-500" @click="close">&times;</button>
                </div>
                <label class="mt-5 block font-semibold" for="role-name">Role name</label>
                <input id="role-name" v-model="form.name" required maxlength="100" pattern="[A-Za-z0-9_-]+" class="mt-2" placeholder="e.g. compliance_officer" />
                <p class="mt-1 text-xs text-slate-500">Use letters, numbers, underscores, or hyphens.</p>
                <InputError :message="form.errors.name" class="mt-2" />
                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" class="rounded-xl border px-5 py-3 font-bold" @click="close">Cancel</button>
                    <button class="form-button inline-flex items-center gap-2" :disabled="form.processing"><ActionIcon name="save" /> {{ form.processing ? 'Saving...' : 'Save role' }}</button>
                </div>
            </form>
        </div>
    </AccessControlLayout>
</template>
