<script setup>
import AccessControlLayout from '@/Components/Admin/AccessControl/AccessControlLayout.vue';
import ActionIcon from '@/Components/Admin/AccessControl/ActionIcon.vue';
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({ roles: Array, selectedRoleId: Number, permissionGroups: Array });
const roleSearch = ref('');
const permissionSearch = ref('');
const selectedRole = computed(() => props.roles.find(role => role.id === props.selectedRoleId));
const form = useForm({ permission_ids: [...(selectedRole.value?.permission_ids || [])] });
const filteredRoles = computed(() => props.roles.filter(role => role.name.toLowerCase().includes(roleSearch.value.toLowerCase())));
const filteredGroups = computed(() => props.permissionGroups.map(group => ({
    ...group,
    permissions: group.permissions.filter(permission =>
        permission.name.toLowerCase().includes(permissionSearch.value.toLowerCase()),
    ),
})).filter(group => group.permissions.length));
const title = value => value.replaceAll('_', ' ').replaceAll('.', ' / ').replace(/\b\w/g, character => character.toUpperCase());
const isChecked = id => form.permission_ids.includes(id);
const isRequired = permission => ['super_admin', 'admin'].includes(selectedRole.value?.name)
    && ['access.manage', 'staff.manage'].includes(permission.name);
const groupChecked = group => group.permissions.length > 0 && group.permissions.every(permission => isChecked(permission.id));
const groupIndeterminate = group => group.permissions.some(permission => isChecked(permission.id)) && !groupChecked(group);

watch(() => props.selectedRoleId, () => {
    form.permission_ids = [...(selectedRole.value?.permission_ids || [])];
    form.clearErrors();
});

const selectRole = role => {
    if (role.id !== props.selectedRoleId) {
        router.get(route('admin.access-control.role-permissions'), { role_id: role.id }, { preserveState: true, preserveScroll: true, replace: true });
    }
};

const toggleGroup = group => {
    const ids = group.permissions.filter(permission => !isRequired(permission)).map(permission => permission.id);
    form.permission_ids = groupChecked(group)
        ? form.permission_ids.filter(id => !ids.includes(id))
        : [...new Set([...form.permission_ids, ...ids])];
};

const togglePermission = permission => {
    if (isRequired(permission)) return;
    form.permission_ids = isChecked(permission.id)
        ? form.permission_ids.filter(id => id !== permission.id)
        : [...form.permission_ids, permission.id];
};

const save = () => {
    if (!selectedRole.value) return;
    form.put(route('admin.access-control.role-permissions.update', selectedRole.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            form.permission_ids = [...(selectedRole.value?.permission_ids || [])];
        },
    });
};
</script>

<template>
    <AccessControlLayout active="role-permissions" title="Role Permissions" description="Choose a role, then check the permissions it should receive. Group checkboxes select or clear all visible permissions in a section.">
        <div class="mt-6 grid gap-6 xl:grid-cols-[minmax(240px,0.75fr)_minmax(0,2fr)]">
            <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <h2 class="font-bold text-[#0B3D6E]">Select a role</h2>
                <label class="sr-only" for="role-filter">Search roles</label>
                <input id="role-filter" v-model="roleSearch" type="search" class="mt-3 w-full" placeholder="Search roles" />
                <div class="mt-3 max-h-[34rem] space-y-1 overflow-y-auto">
                    <button
                        v-for="role in filteredRoles"
                        :key="role.id"
                        type="button"
                        class="flex w-full items-center justify-between rounded-xl px-3 py-3 text-left"
                        :class="selectedRoleId === role.id ? 'bg-[#0B3D6E] text-white' : 'hover:bg-slate-50'"
                        @click="selectRole(role)"
                    >
                        <span>
                            <span class="block font-semibold">{{ title(role.name) }}</span>
                            <span class="block text-xs opacity-70">{{ role.permission_ids.length }} assigned</span>
                        </span>
                        <span v-if="selectedRoleId === role.id" class="text-xs font-bold">Selected</span>
                    </button>
                    <p v-if="!filteredRoles.length" class="p-4 text-sm text-slate-500">No roles match this search.</p>
                </div>
            </section>

            <section v-if="selectedRole" class="min-w-0">
                <div class="flex flex-wrap items-end justify-between gap-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div>
                        <h2 class="text-xl font-bold">{{ title(selectedRole.name) }} permissions</h2>
                        <p class="mt-1 text-sm text-slate-500">{{ form.permission_ids.length }} selected</p>
                    </div>
                    <button type="button" class="form-button inline-flex items-center gap-2" :disabled="form.processing" @click="save">
                        <ActionIcon name="save" /> {{ form.processing ? 'Saving...' : 'Save permissions' }}
                    </button>
                </div>
                <p v-if="form.errors.permission_ids" role="alert" class="mt-3 text-sm text-red-600">{{ form.errors.permission_ids }}</p>
                <label class="sr-only" for="permission-filter">Search permissions</label>
                <input id="permission-filter" v-model="permissionSearch" type="search" class="mt-4 w-full rounded-xl border-slate-300" placeholder="Search permissions" />

                <div class="mt-4 grid gap-4 lg:grid-cols-2">
                    <section v-for="group in filteredGroups" :key="group.label" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                        <div class="flex items-center gap-3 border-b border-slate-100 pb-3">
                            <input
                                type="checkbox"
                                :checked="groupChecked(group)"
                                :indeterminate="groupIndeterminate(group)"
                                :aria-label="`Select all ${group.label} permissions`"
                                @change="toggleGroup(group)"
                            />
                            <h3 class="font-bold text-[#0B3D6E]">{{ group.label }}</h3>
                        </div>
                        <div class="mt-2 space-y-1">
                            <label v-for="permission in group.permissions" :key="permission.id" class="flex items-center gap-3 rounded-lg px-2 py-2 hover:bg-slate-50" :class="isRequired(permission) ? 'cursor-not-allowed opacity-60' : 'cursor-pointer'">
                                <input
                                    type="checkbox"
                                    :checked="isChecked(permission.id)"
                                    :disabled="isRequired(permission)"
                                    @change="togglePermission(permission)"
                                />
                                <span class="text-sm font-semibold text-slate-700">{{ title(permission.name) }}</span>
                                <span v-if="isRequired(permission)" class="ml-auto text-[11px] text-slate-400">Required</span>
                            </label>
                        </div>
                    </section>
                </div>
            </section>
            <p v-else class="rounded-2xl border border-slate-200 bg-white p-8 text-slate-500">Create a role before assigning permissions.</p>
        </div>
    </AccessControlLayout>
</template>
