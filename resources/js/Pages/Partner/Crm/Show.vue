<script setup>
import PartnerLayout from '@/Layouts/PartnerLayout.vue';
import FormErrors from '@/Components/Partner/FormErrors.vue';
import { Link, useForm } from '@inertiajs/vue3';

const props = defineProps({ lead: Object, statuses: Array, nextStatus: String, products: Array, canContinueRegistration: Boolean, clientHasPortalAccess: Boolean, registrationInvitationPending: Boolean });
const details = useForm({
    name: props.lead.name,
    organization: props.lead.organization || '',
    email: props.lead.email,
    phone: props.lead.phone || '',
    location: props.lead.location || '',
    organization_type: props.lead.organization_type || '',
    number_of_users: props.lead.number_of_users || '',
    product_id: props.lead.product_id || '',
    follow_up_date: props.lead.follow_up_date ? props.lead.follow_up_date.slice(0, 10) : '',
});
const stage = useForm({ status: '' });
const registration = useForm({});
const label = status => status.replaceAll('_', ' ');
const advance = status => {
    stage.status = status;
    stage.put(route('partner.crm.advance', props.lead.uuid), { preserveScroll: true });
};
const continueRegistration = () => registration.post(route('partner.customers.invitation', props.lead.converted_customer.uuid), { preserveScroll: true });
</script>

<template>
    <PartnerLayout :title="lead.organization || lead.name">
        <Link :href="route('partner.crm.index')" class="mb-5 inline-block font-bold text-cyan-700">Back to CRM pipeline</Link>
        <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_340px]">
            <div class="space-y-6">
                <section class="rounded-2xl bg-white p-6">
                    <p class="text-sm text-slate-500">{{ lead.lead_number }}</p>
                    <h2 class="mt-2 text-xl font-bold">Pipeline progress</h2>
                    <p class="mt-2">Current stage: <strong class="capitalize">{{ label(lead.status) }}</strong></p>
                    <ol class="mt-4 flex flex-wrap gap-2" aria-label="Pipeline stages">
                        <li v-for="status in statuses" :key="status" class="rounded-full px-3 py-1 text-sm capitalize" :class="status === lead.status ? 'bg-cyan-100 font-bold text-cyan-900' : 'bg-slate-100 text-slate-600'">{{ label(status) }}</li>
                    </ol>
                    <FormErrors :errors="stage.errors" />
                    <div v-if="nextStatus" class="mt-5 flex flex-wrap gap-3">
                        <button type="button" class="form-button" :disabled="stage.processing" @click="advance(nextStatus)">Advance to {{ label(nextStatus) }}</button>
                        <button type="button" class="rounded-lg border border-red-300 px-4 py-2 font-bold text-red-700" :disabled="stage.processing" @click="advance('lost')">Mark lost</button>
                    </div>
                    <p v-else class="mt-4 text-sm text-slate-600">This opportunity is closed. Contact the sales team if it needs to be reopened.</p>
                    <div v-if="lead.status === 'won' && lead.converted_customer" class="mt-5 rounded-lg bg-emerald-50 p-4 text-emerald-900">
                        <p class="font-semibold">Opportunity won and linked to client {{ lead.converted_customer.customer_number }}.</p>
                        <p class="mt-1 text-sm">Client: {{ lead.converted_customer.name }} · {{ lead.converted_customer.email || 'No email address' }}</p>
                        <p v-if="clientHasPortalAccess" class="mt-3 text-sm">This client already has portal access.</p>
                        <p v-else-if="!lead.converted_customer.email" class="mt-3 text-sm">Add an email address to the client record before continuing registration.</p>
                        <p v-else-if="!canContinueRegistration" class="mt-3 text-sm">You do not have permission to send client invitations.</p>
                        <FormErrors :errors="registration.errors" />
                        <div class="mt-3 flex flex-wrap gap-3">
                            <button v-if="canContinueRegistration" type="button" class="rounded-lg bg-emerald-800 px-4 py-2 font-bold text-white disabled:opacity-50" :disabled="registration.processing" @click="continueRegistration">
                                {{ registrationInvitationPending ? 'Resend registration invitation' : 'Continue client registration' }}
                            </button>
                            <Link :href="route('partner.customers.subscriptions.create', lead.converted_customer.uuid)" class="rounded-lg border border-emerald-800 px-4 py-2 font-bold text-emerald-900">Set up subscription</Link>
                            <Link :href="route('partner.customers')" class="rounded-lg px-4 py-2 font-bold underline">View clients</Link>
                        </div>
                    </div>
                </section>
                <section class="rounded-2xl bg-white p-6">
                    <h2 class="text-xl font-bold">Stage history</h2>
                    <ol class="mt-4 space-y-3">
                        <li v-for="entry in lead.stage_history" :key="entry.id" class="border-l-2 border-cyan-200 pl-4">
                            <strong class="capitalize">{{ label(entry.status) }}</strong>
                            <span class="block text-sm text-slate-600">{{ new Date(entry.entered_at).toLocaleString() }}</span>
                        </li>
                    </ol>
                    <p v-if="!lead.stage_history.length" class="mt-4 text-sm text-slate-600">No stage dates are available yet.</p>
                </section>
            </div>
            <form class="form-card" @submit.prevent="details.put(route('partner.crm.update', lead.uuid), { preserveScroll: true })">
                <h2 class="text-xl font-bold">Opportunity details</h2>
                <FormErrors :errors="details.errors" />
                <div class="space-y-4">
                    <label>Contact name<input v-model="details.name" required maxlength="255" /></label>
                    <label>Organisation<input v-model="details.organization" maxlength="255" /></label>
                    <label>Email<input v-model="details.email" type="email" maxlength="255" /></label>
                    <label>Phone<input v-model="details.phone" type="tel" maxlength="40" /></label>
                    <label>Location<input v-model="details.location" maxlength="255" /></label>
                    <label>Organisation type<input v-model="details.organization_type" maxlength="100" /></label>
                    <label>Estimated users<input v-model="details.number_of_users" type="number" min="1" max="10000000" /></label>
                    <label v-if="products.length">Product of interest<select v-model="details.product_id"><option value="">Not decided</option><option v-for="product in products" :key="product.id" :value="product.id">{{ product.name }}</option></select></label>
                    <label>Follow-up date<input v-model="details.follow_up_date" type="date" /></label>
                </div>
                <button class="form-button mt-4" :disabled="details.processing">Save details</button>
            </form>
        </div>
    </PartnerLayout>
</template>
