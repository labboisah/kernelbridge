<script setup>
import PartnerLayout from '@/Layouts/PartnerLayout.vue';
import Pagination from '@/Components/Partner/Pagination.vue';
import FormErrors from '@/Components/Partner/FormErrors.vue';
import { Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

defineProps({ customers: Object, products: Array });
const organizationTypes = [
    ['school', 'School'], ['university', 'University'], ['healthcare', 'Healthcare'], ['business', 'Business'],
    ['hospitality', 'Hospitality'], ['government', 'Government'], ['nonprofit', 'Nonprofit'], ['other', 'Other'],
];
const editing = ref(null);
const creating = ref(false);
const form = useForm({ name: '', email: '', phone: '' });
const invitation = useForm({});
const newClient = useForm({
    name: '', type: 'organization', email: '', phone: '', contact_name: '', contact_role: '',
    organization_type: '', estimated_number_of_users: '', billing_address: '',
    city: '', state: '', product_ids: [],
});
const cancelCreate = () => {
    creating.value = false;
    newClient.reset();
    newClient.clearErrors();
};
const create = () => {
    editing.value = null;
    creating.value = true;
};
const edit = client => {
    cancelCreate();
    editing.value = client.uuid;
    form.name = client.name;
    form.email = client.email || '';
    form.phone = client.phone || '';
    form.clearErrors();
};
const reset = () => {
    editing.value = null;
    form.reset();
};
const submit = () => form.put(route('partner.customers.update', editing.value), {
    preserveScroll: true,
    onSuccess: reset,
});
const submitNew = () => newClient.post(route('partner.customers.store'), {
    preserveScroll: true,
    onSuccess: cancelCreate,
});
const sendInvitation = client => invitation.post(route('partner.customers.invitation', client.uuid), {
    preserveScroll: true,
});
</script>

<template>
    <PartnerLayout title="Clients">
        <p class="mb-6 text-slate-600">Register a client directly, or add prospects through the PCR and CRM pipeline.</p>
        <div class="mb-6 flex flex-wrap gap-3">
            <button type="button" class="rounded-lg bg-[#0B3D6E] px-4 py-2.5 font-semibold text-white" @click="create">New Client</button>
            <Link :href="route('partner.pcr.index')" class="rounded-lg border border-[#0B3D6E] px-4 py-2.5 font-semibold text-[#0B3D6E]">Open PCR register</Link>
        </div>

        <form v-if="creating" class="form-card mb-6" @submit.prevent="submitNew">
            <h2 class="text-xl font-bold">New client</h2>
            <p class="mt-2 text-sm text-slate-600">New clients are active immediately. An invitation will be sent to their email. After registration, you can choose a subscription plan.</p>
            <FormErrors :errors="newClient.errors" />
            <div class="form-grid">
                <label>Client name<input v-model="newClient.name" required maxlength="255" /></label>
                <label>Client type
                    <select v-model="newClient.type" required>
                        <option value="organization">Organization</option>
                        <option value="individual">Individual</option>
                    </select>
                </label>
                <label>Contact email<input v-model="newClient.email" type="email" maxlength="255" required /></label>
                <label>Phone<input v-model="newClient.phone" type="tel" maxlength="30" /></label>
                <label>Contact person<input v-model="newClient.contact_name" maxlength="180" /></label>
                <label>Contact person's role<input v-model="newClient.contact_role" maxlength="120" /></label>
                <label>Organisation type
                    <select v-model="newClient.organization_type">
                        <option value="">Not specified</option>
                        <option v-for="type in organizationTypes" :key="type[0]" :value="type[0]">{{ type[1] }}</option>
                    </select>
                </label>
                <label>Estimated users<input v-model="newClient.estimated_number_of_users" type="number" min="1" max="10000000" /></label>
                <label>City<input v-model="newClient.city" maxlength="120" /></label>
                <label>State<input v-model="newClient.state" maxlength="120" /></label>
            </div>
            <label>Business address<textarea v-model="newClient.billing_address" rows="3" maxlength="2000" /></label>
            <fieldset>
                <legend class="font-semibold">Products of interest (optional)</legend>
                <p class="mb-2 text-sm text-slate-600">Select the products the client is interested in; this does not create a subscription.</p>
                <div v-if="products.length" class="grid gap-2 sm:grid-cols-2">
                    <label v-for="product in products" :key="product.id" class="!flex items-center gap-2">
                        <input v-model="newClient.product_ids" type="checkbox" :value="product.id" class="!mt-0" />
                        <span>{{ product.name }}</span>
                    </label>
                </div>
                <p v-else class="text-sm text-slate-600">No products are currently available.</p>
            </fieldset>
            <div class="flex gap-4">
                <button class="form-button" :disabled="newClient.processing">Register client</button>
                <button type="button" :disabled="newClient.processing" @click="cancelCreate">Cancel</button>
            </div>
        </form>

        <form v-if="editing" class="form-card mb-6" @submit.prevent="submit">
            <h2 class="text-xl font-bold">Edit client</h2>
            <p class="mt-2 text-sm text-slate-600">Keep at least an email address or phone number on file.</p>
            <FormErrors :errors="form.errors" />
            <div class="form-grid">
                <label>Client name<input v-model="form.name" required maxlength="255" /></label>
                <label>Contact email<input v-model="form.email" type="email" maxlength="255" /></label>
                <label>Phone<input v-model="form.phone" type="tel" maxlength="30" /></label>
            </div>
            <div class="flex gap-4">
                <button class="form-button" :disabled="form.processing">Save changes</button>
                <button type="button" @click="reset">Cancel</button>
            </div>
        </form>

        <FormErrors :errors="invitation.errors" />
        <div class="space-y-3">
            <article v-for="client in customers.data" :key="client.uuid" class="flex flex-wrap items-center justify-between gap-4 rounded-xl bg-white p-5">
                <div>
                    <h2 class="font-bold">{{ client.name }}</h2>
                    <p class="text-sm text-slate-600">{{ client.customer_number }} · {{ client.email || 'No email' }} · {{ client.phone || 'No phone' }}</p>
                    <details class="mt-2 text-sm text-slate-600">
                        <summary class="cursor-pointer font-semibold text-cyan-700">Business and product details</summary>
                        <dl class="mt-2 space-y-1">
                            <div><dt class="inline font-semibold">Client type: </dt><dd class="inline capitalize">{{ client.type }}</dd></div>
                            <div><dt class="inline font-semibold">Organisation type: </dt><dd class="inline capitalize">{{ client.metadata?.organization_type || 'Not specified' }}</dd></div>
                            <div><dt class="inline font-semibold">Estimated users: </dt><dd class="inline">{{ client.metadata?.estimated_number_of_users || 'Not specified' }}</dd></div>
                            <div><dt class="inline font-semibold">Contact person: </dt><dd class="inline">{{ client.contacts?.[0]?.name || 'Not specified' }}<span v-if="client.contacts?.[0]?.job_title"> ({{ client.contacts[0].job_title }})</span></dd></div>
                            <div><dt class="inline font-semibold">Business address: </dt><dd class="inline whitespace-pre-line">{{ [client.billing_address, client.city, client.state].filter(Boolean).join(', ') || 'Not specified' }}</dd></div>
                            <div><dt class="inline font-semibold">Products: </dt><dd class="inline">{{ client.products?.map(product => product.name).join(', ') || 'Not specified' }}</dd></div>
                        </dl>
                    </details>
                    <div v-if="client.crm_leads?.length" class="mt-3 text-sm">
                        <p class="font-semibold text-slate-700">CRM records</p>
                        <div class="mt-1 flex flex-wrap gap-x-3 gap-y-1">
                            <Link v-for="lead in client.crm_leads" :key="lead.uuid" :href="route('partner.crm.show', lead.uuid)" class="font-semibold text-cyan-700 underline">
                                {{ lead.lead_number }} · {{ lead.organization || lead.name }} ({{ lead.status.replaceAll('_', ' ') }})
                            </Link>
                        </div>
                    </div>
                </div>
                <div class="flex flex-wrap gap-4">
                    <Link :href="route('partner.customers.subscriptions.create', client.uuid)" class="rounded-lg bg-[#0B3D6E] px-4 py-2 font-semibold text-white">Add subscription</Link>
                    <button class="font-bold text-cyan-700" :disabled="invitation.processing" @click="sendInvitation(client)">Send client invitation</button>
                    <button class="font-bold text-cyan-700" @click="edit(client)">Edit</button>
                </div>
            </article>
            <p v-if="!customers.data.length" class="rounded-xl bg-white p-5 text-slate-600">No clients yet. Register a client or win a CRM opportunity to add one.</p>
        </div>
        <Pagination :page="customers" />
    </PartnerLayout>
</template>
