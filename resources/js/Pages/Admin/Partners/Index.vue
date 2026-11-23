<script setup>
import PortalLayout from '@/Layouts/PortalLayout.vue';
import PartnerReview from '@/Components/Admin/PartnerReview.vue';
import PartnerApplicationEdit from '@/Components/Admin/PartnerApplicationEdit.vue';
import PartnerOrganizationUpgradeReview from '@/Components/Admin/PartnerOrganizationUpgradeReview.vue';
import Pagination from '@/Components/Partner/Pagination.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({ applications: Object, organizationUpgrades: Object, types: Object, questionnaire: Array, filters: Object });
const filters = useForm({ search: props.filters.search, status: props.filters.status });
const deletion = useForm({});
const selectedId = ref(null);
const editing = ref(false);
const selected = computed(() => props.applications.data.find(application => application.id === selectedId.value));
watch(() => props.applications.data, () => { if (!selected.value) { selectedId.value = null; editing.value = false; } });
const showApplication = (application, edit = false) => {
    selectedId.value = application.id;
    editing.value = edit;
};
const applyFilters = () => {
    selectedId.value = null;
    filters.get(route('admin.partners.index'), { preserveState: true, preserveScroll: true });
};
const remove = application => {
    if (!window.confirm(`Permanently delete ${application.user.name}'s ${props.types[application.type]?.label || application.type} application? Their account and other records will remain. Approved sales-agent access will end.`)) return;
    deletion.delete(route('admin.partners.destroy', application.id), {
        preserveScroll: true,
        onSuccess: () => { selectedId.value = null; editing.value = false; },
    });
};
const date = value => value ? new Date(value).toLocaleDateString() : 'Not submitted';
</script>

<template>
    <Head title="Partner applications" />
    <PortalLayout area="Administration">
        <div class="mb-6">
            <h1 class="text-3xl font-bold">Partner applications</h1>
            <p class="mt-2 text-slate-600">View and manage applications at every status.</p>
            <Link :href="route('admin.partners.commissions')" class="mt-4 inline-block font-bold text-cyan-700">Configure product commissions</Link>
        </div>
        <p v-if="$page.props.flash.success" role="status" class="success-message mb-5">{{ $page.props.flash.success }}</p>
        <p v-if="$page.props.flash.error" role="alert" class="mb-5 rounded-lg bg-red-50 p-4 text-red-700">{{ $page.props.flash.error }}</p>
        <p v-if="deletion.errors.application_delete" role="alert" class="mb-5 rounded-lg bg-red-50 p-4 text-red-700">{{ deletion.errors.application_delete }}</p>

        <section>
            <h2 class="mb-4 text-2xl font-bold">All applications</h2>
            <form class="mb-4 flex flex-wrap items-end gap-3 rounded-xl bg-white p-4" @submit.prevent="applyFilters">
                <label class="min-w-52 flex-1">Search by name, email or organization<input v-model="filters.search" maxlength="100" class="mt-1" /></label>
                <label>Status<select v-model="filters.status" class="mt-1"><option value="">All statuses</option><option value="draft">Draft</option><option value="submitted">Submitted</option><option value="approved">Approved</option><option value="declined">Declined</option></select></label>
                <button class="form-button" :disabled="filters.processing">Search</button>
            </form>
            <div class="overflow-x-auto rounded-xl bg-white shadow-sm">
                <table class="w-full min-w-[760px] text-left text-sm">
                    <thead class="bg-slate-50 text-slate-600"><tr><th class="p-4">Partner</th><th class="p-4">Type</th><th class="p-4">Organization</th><th class="p-4">Status</th><th class="p-4">Submitted</th><th class="p-4">Actions</th></tr></thead>
                    <tbody>
                        <tr v-for="application in applications.data" :key="application.id" class="border-t border-slate-100">
                            <td class="p-4"><strong>{{ application.user.name }}</strong><div class="text-slate-500">{{ application.user.email }}</div></td>
                            <td class="p-4">{{ types[application.type]?.label || 'Customer (legacy application)' }}</td>
                            <td class="p-4">{{ application.organisation || '—' }}</td>
                            <td class="p-4 capitalize">{{ application.status }}</td>
                            <td class="p-4">{{ date(application.submitted_at) }}</td>
                            <td class="p-4"><div class="flex flex-wrap gap-3">
                                <button type="button" class="font-semibold text-cyan-700 underline" @click="showApplication(application)">View<span class="sr-only"> {{ application.user.name }}</span></button>
                                <button type="button" class="font-semibold text-cyan-700 underline" @click="showApplication(application, true)">Edit<span class="sr-only"> {{ application.user.name }}</span></button>
                                <button type="button" class="font-semibold text-red-700 underline" :disabled="deletion.processing" @click="remove(application)">Delete<span class="sr-only"> {{ application.user.name }}</span></button>
                            </div></td>
                        </tr>
                        <tr v-if="!applications.data.length"><td colspan="6" class="p-6 text-slate-600">No applications match these filters.</td></tr>
                    </tbody>
                </table>
            </div>
            <Pagination :page="applications" />
            <div v-if="selected" class="mt-6">
                <div class="mb-3 flex items-center justify-between gap-3"><h3 class="text-xl font-bold">{{ editing ? 'Edit application' : 'Application details and review' }}</h3><button type="button" class="text-sm font-semibold underline" @click="selectedId = null">Close</button></div>
                <PartnerApplicationEdit v-if="editing" :key="`edit-${selected.id}`" :application="selected" :questionnaire="questionnaire" @saved="editing = false" @cancel="editing = false" />
                <PartnerReview v-else :key="`view-${selected.id}`" :application="selected" :questionnaire="questionnaire" :label="types[selected.type]?.label || 'Customer (legacy application)'" />
            </div>
        </section>

        <section class="my-10">
            <h2 class="mb-4 text-2xl font-bold">Organization partner upgrades</h2>
            <p v-if="!organizationUpgrades.data.length" class="rounded-xl bg-white p-6">No organization upgrade requests yet.</p>
            <div class="space-y-6"><PartnerOrganizationUpgradeReview v-for="upgrade in organizationUpgrades.data" :key="upgrade.id" :upgrade="upgrade" /></div>
            <Pagination :page="organizationUpgrades" />
        </section>
    </PortalLayout>
</template>
