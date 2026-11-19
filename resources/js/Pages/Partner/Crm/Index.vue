<script setup>
import PartnerLayout from '@/Layouts/PartnerLayout.vue';
import Pagination from '@/Components/Partner/Pagination.vue';
import FormErrors from '@/Components/Partner/FormErrors.vue';
import { Link, useForm } from '@inertiajs/vue3';

defineProps({ leads: Object, statuses: Array });
const form = useForm({ name: '', organization: '', email: '', phone: '', location: '', follow_up_date: '' });
const label = status => status.replaceAll('_', ' ');
const submit = () => form.post(route('partner.crm.store'), { preserveScroll: true });
</script>

<template>
    <PartnerLayout title="CRM pipeline">
        <p class="mb-6 text-slate-600">Create and track your opportunities through the sales pipeline. The company sales team can also manage these leads.</p>
        <form class="form-card mb-6" @submit.prevent="submit">
            <h2 class="text-xl font-bold">Add an opportunity</h2>
            <FormErrors :errors="form.errors" />
            <div class="form-grid">
                <label>Contact name<input v-model="form.name" required maxlength="255" /></label>
                <label>Organisation<input v-model="form.organization" maxlength="255" /></label>
                <label>Email<input v-model="form.email" type="email" required maxlength="255" /></label>
                <label>Phone<input v-model="form.phone" type="tel" maxlength="40" /></label>
                <label>Location<input v-model="form.location" maxlength="255" /></label>
                <label>Follow-up date<input v-model="form.follow_up_date" type="date" /></label>
            </div>
            <button class="form-button" :disabled="form.processing">Create opportunity</button>
        </form>

        <section aria-label="Pipeline stages">
            <h2 class="mb-2 text-xl font-bold">Your pipeline</h2>
            <p class="mb-4 text-sm text-slate-600">Showing opportunities on page {{ leads.current_page }} of {{ leads.last_page }}. Open an opportunity to view its stage history and update it.</p>
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <section v-for="status in statuses" :key="status" class="rounded-xl bg-white p-4">
                    <h3 class="font-bold capitalize">{{ label(status) }}</h3>
                    <div class="mt-3 space-y-3">
                        <Link v-for="lead in leads.data.filter(item => item.status === status)" :key="lead.uuid" :href="route('partner.crm.show', lead.uuid)" class="block rounded-lg border p-3 hover:bg-slate-50">
                            <span class="block font-bold">{{ lead.organization || lead.name }}</span>
                            <span class="block text-sm text-slate-600">{{ lead.name }} · {{ lead.lead_number }}</span>
                        </Link>
                        <p v-if="!leads.data.some(item => item.status === status)" class="text-sm text-slate-500">None on this page.</p>
                    </div>
                </section>
            </div>
            <Pagination :page="leads" />
        </section>
    </PartnerLayout>
</template>
