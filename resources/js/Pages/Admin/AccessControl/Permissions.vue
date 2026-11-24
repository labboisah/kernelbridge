<script setup>
import AccessControlLayout from '@/Components/Admin/AccessControl/AccessControlLayout.vue';
import ActionIcon from '@/Components/Admin/AccessControl/ActionIcon.vue';
import InputError from '@/Components/InputError.vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';

const props = defineProps({ permissions: Object, filters: Object });
const filter = reactive({ search: props.filters.search || '' });
const editing = ref(null);
const open = ref(false);
const form = useForm({ name: '' });
const title = value => value.replaceAll('_', ' ').replaceAll('.', ' / ').replace(/\b\w/g, character => character.toUpperCase());
const applyFilters = () => router.get(route('admin.access-control.permissions'), filter, { preserveState: true, replace: true });

const createPermission = () => {
    editing.value = null;
    form.reset();
    form.clearErrors();
    open.value = true;
};

const editPermission = permission => {
    editing.value = permission;
    form.name = permission.name;
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
        ? route('admin.access-control.permissions.update', editing.value.id)
        : route('admin.access-control.permissions.store'),
    { preserveScroll: true, onSuccess: close },
);

const deletePermission = permission => {
    if (window.confirm(`Delete "${permission.name}"? Any role and temporary-user assignments for it will also be removed.`)) {
        router.delete(route('admin.access-control.permissions.destroy', permission.id), { preserveScroll: true });
    }
};
</script>

<template>
    <AccessControlLayout active="access-permissions" title="Permissions" description="Create custom permissions and maintain the permissions available for role and temporary user assignments. System permissions are protected.">
        <template #actions>
            <button type="button" class="form-button inline-flex items-center gap-2" @click="createPermission">
                <ActionIcon name="create" /> Create permission
            </button>
        </template>

        <form class="mt-6 flex flex-wrap gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm" @submit.prevent="applyFilters">
            <label class="sr-only" for="permission-search">Search permissions</label>
            <input id="permission-search" v-model="filter.search" type="search" class="min-w-[220px] flex-1" placeholder="Search permission name" />
            <button class="rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50">
                <span class="inline-flex items-center gap-2"><ActionIcon name="search" /> Search</span>
            </button>
        </form>

        <div class="mt-5 overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
            <table class="w-full min-w-[640px] text-left">
                <thead class="border-b bg-slate-50 text-xs font-bold uppercase text-slate-500">
                    <tr><th class="px-5 py-3">Permission</th><th class="px-5 py-3">Type</th><th class="px-5 py-3 text-right">Actions</th></tr>
                </thead>
                <tbody>
                    <tr v-for="permission in permissions.data" :key="permission.id" class="border-b last:border-0">
                        <td class="px-5 py-4 font-semibold text-[#0B3D6E]">{{ title(permission.name) }} <span class="ml-1 text-xs font-normal text-slate-400">({{ permission.name }})</span></td>
                        <td class="px-5 py-4 text-sm text-slate-600">{{ permission.is_system ? 'System permission' : 'Custom permission' }}</td>
                        <td class="px-5 py-4">
                            <div v-if="!permission.is_system" class="flex justify-end gap-2">
                                <button type="button" class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-2 text-sm font-bold text-slate-700 hover:bg-slate-50" @click="editPermission(permission)">
                                    <ActionIcon name="edit" /> Edit
                                </button>
                                <button type="button" class="inline-flex items-center gap-1.5 rounded-lg border border-red-200 px-3 py-2 text-sm font-bold text-red-700 hover:bg-red-50" @click="deletePermission(permission)">
                                    <ActionIcon name="delete" /> Delete
                                </button>
                            </div>
                            <span v-else class="block text-right text-xs text-slate-400">System permission protected</span>
                        </td>
                    </tr>
                    <tr v-if="!permissions.data.length"><td colspan="3" class="p-10 text-center text-slate-500">No permissions match this search.</td></tr>
                </tbody>
            </table>
        </div>

        <nav v-if="permissions.last_page > 1" class="mt-5 flex flex-wrap gap-2" aria-label="Permission pages">
            <Link v-for="link in permissions.links" :key="link.label" :href="link.url || '#'" class="rounded-lg border px-3 py-2 text-sm" :class="[link.active ? 'bg-[#0B3D6E] text-white' : '', !link.url ? 'pointer-events-none opacity-40' : '']" v-html="link.label" />
        </nav>

        <div v-if="open" class="fixed inset-0 z-50 grid place-items-center overflow-y-auto bg-slate-950/50 p-4" role="dialog" aria-modal="true" @click.self="close">
            <form class="my-8 w-full max-w-lg rounded-2xl bg-white p-6 shadow-2xl" @submit.prevent="save">
                <div class="flex items-center justify-between gap-4">
                    <h2 class="text-xl font-bold">{{ editing ? 'Edit permission' : 'Create permission' }}</h2>
                    <button type="button" aria-label="Close dialog" class="text-2xl text-slate-500" @click="close">&times;</button>
                </div>
                <label class="mt-5 block font-semibold" for="permission-name">Permission name</label>
                <input id="permission-name" v-model="form.name" required maxlength="255" pattern="[A-Za-z0-9._-]+" class="mt-2" placeholder="e.g. report.export" />
                <p class="mt-1 text-xs text-slate-500">Use letters, numbers, periods, underscores, or hyphens.</p>
                <InputError :message="form.errors.name" class="mt-2" />
                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" class="rounded-xl border px-5 py-3 font-bold" @click="close">Cancel</button>
                    <button class="form-button inline-flex items-center gap-2" :disabled="form.processing"><ActionIcon name="save" /> {{ form.processing ? 'Saving...' : 'Save permission' }}</button>
                </div>
            </form>
        </div>
    </AccessControlLayout>
</template>
