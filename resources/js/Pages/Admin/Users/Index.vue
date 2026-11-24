<script setup>
import AdminNavigation from '@/Components/Admin/AdminNavigation.vue';
import InputError from '@/Components/InputError.vue';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

const props = defineProps({ users: Object, filters: Object, roles: Array, currentUserPublicId: String });
const page = usePage();
const filter = reactive({
    search: props.filters.search || '',
    role: props.filters.role || '',
    status: props.filters.status || '',
});
const editing = ref(null);
const open = ref(false);
const form = useForm({ name: '', email: '', roles: [], status: 'active' });
const pageError = computed(() => Object.values(page.props.errors || {}).flat().join(' '));
const applyFilters = () => router.get(route('admin.users.index'), filter, { preserveState: true, replace: true });
const title = value => value.replaceAll('_', ' ').replace(/\b\w/g, character => character.toUpperCase());

const edit = user => {
    editing.value = user;
    form.name = user.name;
    form.email = user.email;
    form.roles = user.roles.map(role => role.name);
    form.status = user.status;
    form.clearErrors();
    open.value = true;
};

const close = () => {
    open.value = false;
    editing.value = null;
    form.reset();
    form.clearErrors();
};

const save = () => form.put(route('admin.users.update', editing.value.public_id), {
    preserveScroll: true,
    onSuccess: close,
});

const sendPasswordReset = user => {
    if (window.confirm(`Send a password reset link to ${user.email}?`)) {
        router.post(route('admin.users.password-reset', user.public_id), {}, { preserveScroll: true });
    }
};

const deleteAccount = user => {
    if (window.confirm(`Delete ${user.name}'s account? Login access will be removed and historic records will be retained.`)) {
        router.delete(route('admin.users.destroy', user.public_id), { preserveScroll: true });
    }
};
</script>

<template>
    <Head title="User accounts" />
    <PortalLayout area="Administration">
        <template #navigation><AdminNavigation active="users" /></template>
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="text-sm font-bold uppercase text-cyan-700">Access control</p>
                <h1 class="mt-1 text-3xl font-bold">User accounts</h1>
                <p class="mt-2 text-slate-600">Manage profile details, roles, access status, password resets, and account deletion.</p>
            </div>
            <Link :href="route('admin.users.deleted')" class="rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50">
                Deleted accounts
            </Link>
        </div>
        <p v-if="$page.props.flash.success" role="status" class="success-message mt-5">{{ $page.props.flash.success }}</p>
        <p v-if="pageError" role="alert" class="mt-5 rounded-xl bg-red-50 p-4 text-sm font-semibold text-red-800">{{ pageError }}</p>

        <form class="mt-6 grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm md:grid-cols-4" @submit.prevent="applyFilters">
            <input v-model="filter.search" type="search" placeholder="Search name or email" />
            <select v-model="filter.role">
                <option value="">All roles</option>
                <option v-for="role in roles" :key="role" :value="role">{{ title(role) }}</option>
            </select>
            <select v-model="filter.status">
                <option value="">All statuses</option>
                <option value="active">Active</option>
                <option value="suspended">Suspended</option>
            </select>
            <button class="form-button !py-2">Filter</button>
        </form>

        <div class="mt-6 overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
            <table class="w-full min-w-[900px] text-left">
                <thead class="border-b bg-slate-50 text-xs font-bold uppercase text-slate-500">
                    <tr>
                        <th class="px-5 py-3">User</th>
                        <th class="px-5 py-3">Roles</th>
                        <th class="px-5 py-3">Status</th>
                        <th class="px-5 py-3">Email verification</th>
                        <th class="px-5 py-3">Updated</th>
                        <th class="px-5 py-3 text-right">Actions</th>
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
                        <td class="px-5 py-4 text-sm text-slate-600">{{ user.email_verified_at ? 'Verified' : 'Not verified' }}</td>
                        <td class="px-5 py-4 text-sm text-slate-500">{{ new Date(user.updated_at).toLocaleDateString() }}</td>
                        <td class="px-5 py-4 text-right">
                            <div class="flex justify-end gap-2">
                                <button type="button" class="rounded-lg border px-3 py-2 text-sm font-bold" @click="edit(user)">Edit</button>
                                <button type="button" class="rounded-lg border px-3 py-2 text-sm font-bold" @click="sendPasswordReset(user)">Reset password</button>
                                <button
                                    v-if="user.public_id !== currentUserPublicId"
                                    type="button"
                                    class="rounded-lg border border-red-200 px-3 py-2 text-sm font-bold text-red-700 hover:bg-red-50"
                                    @click="deleteAccount(user)"
                                >Delete</button>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!users.data.length">
                        <td colspan="6" class="p-10 text-center text-slate-500">No user accounts match these filters.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <nav v-if="users.last_page > 1" class="mt-5 flex flex-wrap gap-2" aria-label="User account pages">
            <Link
                v-for="link in users.links"
                :key="link.label"
                :href="link.url || '#'"
                class="rounded-lg border px-3 py-2 text-sm"
                :class="[link.active ? 'bg-[#0B3D6E] text-white' : '', !link.url ? 'pointer-events-none opacity-40' : '']"
                v-html="link.label"
            />
        </nav>
        <p class="mt-4 text-xs text-slate-500">Deleted accounts are removed from sign-in but retained as historical records.</p>

        <div v-if="open" class="fixed inset-0 z-50 grid place-items-center overflow-y-auto bg-slate-950/50 p-4" role="dialog" aria-modal="true" @click.self="close">
            <form class="my-8 w-full max-w-2xl rounded-2xl bg-white p-6 shadow-2xl" @submit.prevent="save">
                <div class="flex items-center justify-between gap-4">
                    <h2 class="text-xl font-bold">Edit user account</h2>
                    <button type="button" aria-label="Close dialog" class="text-2xl text-slate-500" @click="close">&times;</button>
                </div>
                <div class="mt-5 grid gap-5 sm:grid-cols-2">
                    <label>Name
                        <input v-model="form.name" required maxlength="255" />
                        <InputError :message="form.errors.name" />
                    </label>
                    <label>Email
                        <input v-model="form.email" type="email" required maxlength="255" />
                        <InputError :message="form.errors.email" />
                    </label>
                    <label class="sm:col-span-2">Account status
                        <select v-model="form.status" required>
                            <option value="active">Active</option>
                            <option value="suspended">Suspended</option>
                        </select>
                        <InputError :message="form.errors.status" />
                    </label>
                </div>
                <fieldset class="mt-6">
                    <legend class="font-semibold">Roles</legend>
                    <div class="mt-3 grid gap-2 sm:grid-cols-2">
                        <label v-for="role in roles" :key="role" class="flex items-center gap-2 rounded-lg border border-slate-200 p-3">
                            <input v-model="form.roles" type="checkbox" :value="role" class="rounded border-slate-300 text-[#0797A5] focus:ring-[#0797A5]" />
                            <span>{{ title(role) }}</span>
                        </label>
                    </div>
                    <InputError :message="form.errors.roles" />
                    <InputError v-for="(_, index) in form.roles" :key="index" :message="form.errors[`roles.${index}`]" />
                    <p v-if="editing && editing.public_id === currentUserPublicId" class="mt-2 text-xs text-slate-500">Your account must remain active with the Super Admin role.</p>
                </fieldset>
                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" class="rounded-xl border px-5 py-3 font-bold" @click="close">Cancel</button>
                    <button class="form-button" :disabled="form.processing">{{ form.processing ? 'Saving...' : 'Save account' }}</button>
                </div>
            </form>
        </div>
    </PortalLayout>
</template>
