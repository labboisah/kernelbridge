<script setup>
import PartnerLayout from '@/Layouts/PartnerLayout.vue';
import Pagination from '@/Components/Partner/Pagination.vue';
import FormErrors from '@/Components/Partner/FormErrors.vue';
import { Link, useForm } from '@inertiajs/vue3';

defineProps({ prospects: Object, products: Array });
const organizationTypes = [
    ['school', 'School'],
    ['university', 'University'],
    ['healthcare', 'Healthcare'],
    ['business', 'Business'],
    ['hospitality', 'Hospitality'],
    ['government', 'Government'],
    ['nonprofit', 'Nonprofit'],
    ['other', 'Other'],
];
const form = useForm({
    name: '',
    organization: '',
    email: '',
    phone: '',
    location: '',
    organization_type: '',
    number_of_users: '',
    product_id: '',
    follow_up_date: '',
    initial_notes: '',
});
const label = status => status.replaceAll('_', ' ');
const submit = () => form.post(route('partner.pcr.store'), {
    preserveScroll: true,
    onSuccess: () => form.reset(),
});
</script>

<template>
    <PartnerLayout title="Prospective Customer Register (PCR)">
        <p class="mb-6 text-slate-600">Register a prospect with the minimum details needed to identify and contact them. Move them to the CRM only after they express interest and consent.</p>

        <form class="form-card mb-8" @submit.prevent="submit">
            <h2 class="text-xl font-bold">Register a prospect</h2>
            <p class="mt-2 text-sm text-slate-600">Email and phone are optional. Do not record sensitive personal information.</p>
            <FormErrors :errors="form.errors" />
            <div class="form-grid">
                <label>Contact name<input v-model="form.name" required maxlength="255" autocomplete="name" /></label>
                <label>Organisation<input v-model="form.organization" maxlength="255" autocomplete="organization" /></label>
                <label>Email (optional)<input v-model="form.email" type="email" maxlength="255" autocomplete="email" /></label>
                <label>Phone (optional)<input v-model="form.phone" type="tel" maxlength="40" autocomplete="tel" /></label>
                <label>Location<input v-model="form.location" maxlength="255" /></label>
                <label>Organisation type<select v-model="form.organization_type"><option value="">Select type</option><option v-for="type in organizationTypes" :key="type[0]" :value="type[0]">{{ type[1] }}</option></select></label>
                <label>Estimated users<input v-model="form.number_of_users" type="number" min="1" max="10000000" /></label>
                <label v-if="products.length">Product of interest<select v-model="form.product_id"><option value="">Not decided</option><option v-for="product in products" :key="product.id" :value="product.id">{{ product.name }}</option></select></label>
                <label>Next follow-up date<input v-model="form.follow_up_date" type="date" /></label>
            </div>
            <label>Initial notes<textarea v-model="form.initial_notes" rows="3" maxlength="5000" placeholder="Relevant business need or context only" /></label>
            <button class="form-button" :disabled="form.processing">Register prospect</button>
        </form>

        <section>
            <h2 class="mb-4 text-xl font-bold">Your PCR records</h2>
            <div class="space-y-3">
                <article v-for="prospect in prospects.data" :key="prospect.uuid" class="flex flex-wrap items-center justify-between gap-4 rounded-xl bg-white p-5">
                    <Link :href="route('partner.pcr.show', prospect.uuid)" class="min-w-0 flex-1 rounded-lg hover:text-[#0B3D6E] focus:outline-none focus:ring-2 focus:ring-cyan-600">
                        <div>
                            <h3 class="font-bold">{{ prospect.organization || prospect.name }}</h3>
                            <p class="mt-1 text-sm text-slate-600">{{ prospect.name }} · {{ prospect.email || prospect.phone || 'No contact details' }}</p>
                            <p v-if="prospect.product" class="mt-1 text-sm text-slate-500">Interest: {{ prospect.product.name }}</p>
                        </div>
                    </Link>
                    <div class="flex flex-wrap items-center gap-3">
                        <span class="rounded-full bg-slate-100 px-3 py-1 text-sm capitalize text-slate-700">{{ label(prospect.status) }}</span>
                        <Link
                            v-if="['prospective', 'interested_pending_consent'].includes(prospect.status)"
                            :href="route('partner.pcr.show', prospect.uuid)"
                            class="rounded-lg bg-[#0B3D6E] px-4 py-2 text-sm font-semibold text-white hover:bg-[#082f55]"
                        >
                            Record visit &amp; consent
                        </Link>
                    </div>
                </article>
                <p v-if="!prospects.data.length" class="rounded-xl bg-white p-5 text-slate-600">No prospects registered yet.</p>
            </div>
            <Pagination :page="prospects" />
        </section>
    </PartnerLayout>
</template>
