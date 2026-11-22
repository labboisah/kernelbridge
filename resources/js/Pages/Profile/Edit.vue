<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import DeleteUserForm from './Partials/DeleteUserForm.vue';
import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm.vue';
import UpdateAdminPhotoForm from './Partials/UpdateAdminPhotoForm.vue';
import DocumentAssetsForm from './Partials/DocumentAssetsForm.vue';
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

defineProps({
    mustVerifyEmail: {
        type: Boolean,
    },
    status: {
        type: String,
    },
    documentAssets: Object,
});
const isAdmin = computed(() => usePage().props.auth.roles?.some(role => ['admin', 'super_admin'].includes(role)));
</script>

<template>
    <Head title="Profile" />

    <component :is="isAdmin ? PortalLayout : AuthenticatedLayout" v-bind="isAdmin ? { area: 'Administration' } : {}">
        <template #header>
            <h2
                class="text-xl font-semibold leading-tight text-gray-800"
            >
                Profile
            </h2>
        </template>

        <h1 v-if="isAdmin" class="mb-6 text-3xl font-bold text-slate-900">Profile</h1>
        <p v-if="isAdmin && $page.props.flash.success" role="status" class="success-message mb-5">{{ $page.props.flash.success }}</p>
        <div :class="isAdmin ? '' : 'py-12'">
            <div :class="isAdmin ? 'space-y-6' : 'mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8'">
                <div v-if="isAdmin" class="bg-white p-4 shadow sm:rounded-lg sm:p-8">
                    <UpdateAdminPhotoForm class="max-w-xl" />
                </div>
                <div v-if="isAdmin" class="bg-white p-4 shadow sm:rounded-lg sm:p-8">
                    <DocumentAssetsForm :assets="documentAssets" class="max-w-xl" />
                </div>
                <div
                    class="bg-white p-4 shadow sm:rounded-lg sm:p-8"
                >
                    <UpdateProfileInformationForm
                        :must-verify-email="mustVerifyEmail"
                        :status="status"
                        class="max-w-xl"
                    />
                </div>

                <div
                    class="bg-white p-4 shadow sm:rounded-lg sm:p-8"
                >
                    <UpdatePasswordForm class="max-w-xl" />
                </div>

                <div
                    class="bg-white p-4 shadow sm:rounded-lg sm:p-8"
                >
                    <DeleteUserForm class="max-w-xl" />
                </div>
            </div>
        </div>
    </component>
</template>
