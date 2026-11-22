<script setup>
import PartnerLayout from '@/Layouts/PartnerLayout.vue';
import FormErrors from '@/Components/Partner/FormErrors.vue';
import Pagination from '@/Components/Partner/Pagination.vue';
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

const props = defineProps({ quotations: Object, prospects: Array, leads: Array, products: Array, plans: Array, vatRate: String });
const form = useForm({
    recipient_type: 'manual', recipient_uuid: '', recipient_name: '', recipient_organization: '',
    recipient_email: '', recipient_phone: '', recipient_location: '', product_id: '', plan_uuid: '',
    description: '', quantity: 1, expires_at: '', terms: '',
});
const recipientOptions = computed(() => form.recipient_type === 'pcr' ? props.prospects : props.leads);
const availablePlans = computed(() => props.plans.filter(plan => plan.product_id === form.product_id));
const selectedPlan = computed(() => availablePlans.value.find(plan => plan.uuid === form.plan_uuid));
const preview = computed(() => {
    if (!selectedPlan.value || !Number.isInteger(Number(form.quantity)) || form.quantity < 1 || form.quantity > 1000) return null;
    const subtotal = Math.round(Number(selectedPlan.value.price) * 100) * Number(form.quantity);
    if (subtotal > 100000000000) return null;
    const tax = Math.round(subtotal * Math.round(Number(props.vatRate) * 100) / 10000);
    return { subtotal: (subtotal / 100).toFixed(2), tax: (tax / 100).toFixed(2), total: ((subtotal + tax) / 100).toFixed(2) };
});
watch(() => form.recipient_type, () => { form.recipient_uuid = ''; });
watch(() => form.product_id, () => { form.plan_uuid = ''; });
const submit = () => form.post(route('partner.quotations.store'), {
    preserveScroll: true,
    onSuccess: () => form.reset(),
});
</script>

<template>
    <PartnerLayout title="Quotation">
        <p class="mb-6 text-slate-600">Prepare a quotation for a prospective client using the published product and plan price. Creating a quotation does not register a client or start a subscription.</p>
        <form class="form-card mb-8" @submit.prevent="submit">
            <h2 class="text-xl font-bold">Create quotation</h2>
            <FormErrors :errors="form.errors" />
            <label>Recipient
                <select v-model="form.recipient_type">
                    <option value="manual">Enter details manually</option>
                    <option value="pcr">Select a PCR prospect</option>
                    <option value="crm">Select a CRM lead</option>
                </select>
            </label>
            <label v-if="form.recipient_type !== 'manual'">Prospect or lead
                <select v-model="form.recipient_uuid" required>
                    <option value="">Select a recipient</option>
                    <option v-for="recipient in recipientOptions" :key="recipient.uuid" :value="recipient.uuid">
                        {{ recipient.name }}{{ recipient.organization ? ` - ${recipient.organization}` : '' }}
                    </option>
                </select>
            </label>
            <div v-if="form.recipient_type === 'manual'" class="form-grid">
                <label>Contact name<input v-model="form.recipient_name" required maxlength="255" /></label>
                <label>Organization<input v-model="form.recipient_organization" maxlength="255" /></label>
                <label>Email<input v-model="form.recipient_email" type="email" maxlength="255" /></label>
                <label>Phone<input v-model="form.recipient_phone" type="tel" maxlength="40" /></label>
                <label>Address or location<input v-model="form.recipient_location" maxlength="255" /></label>
            </div>
            <div class="form-grid">
                <label>Product
                    <select v-model="form.product_id" required>
                        <option value="">Choose a product</option>
                        <option v-for="product in products" :key="product.id" :value="product.id">{{ product.name }}</option>
                    </select>
                </label>
                <label>Plan
                    <select v-model="form.plan_uuid" required>
                        <option value="">Choose a plan</option>
                        <option v-for="plan in availablePlans" :key="plan.uuid" :value="plan.uuid">{{ plan.name }} - {{ plan.currency }} {{ plan.price }} / {{ plan.billing_cycle }}</option>
                    </select>
                </label>
                <label>Quantity<input v-model="form.quantity" type="number" min="1" max="1000" required /></label>
                <label>Valid until (optional)<input v-model="form.expires_at" type="date" /></label>
            </div>
            <p v-if="form.product_id && !availablePlans.length" class="text-sm text-slate-600">No published, priced plans are available for this product.</p>
            <section v-if="selectedPlan" class="rounded-lg bg-slate-50 p-4 text-sm text-slate-700">
                <h3 class="font-bold">Included plan modules and features</h3>
                <p class="mt-1 text-slate-500">These catalogue details are included in the quotation and cannot be edited here.</p>
                <div v-for="module in selectedPlan.modules" :key="module.name" class="mt-3">
                    <p class="font-semibold">{{ module.name }}<span v-if="module.limit">: {{ module.limit }}</span></p>
                    <p v-if="module.description" class="text-slate-500">{{ module.description }}</p>
                    <ul v-if="module.features.length" class="mt-1 list-disc space-y-1 pl-5">
                        <li v-for="feature in module.features" :key="feature.name">{{ feature.name }}<span v-if="feature.limit">: {{ feature.limit }}</span><span v-if="feature.description"> - {{ feature.description }}</span></li>
                    </ul>
                </div>
                <ul v-if="selectedPlan.features.length" class="mt-3 list-disc space-y-1 pl-5">
                    <li v-for="feature in selectedPlan.features" :key="feature.name">{{ feature.name }}<span v-if="feature.limit">: {{ feature.limit }}</span><span v-if="feature.description"> - {{ feature.description }}</span></li>
                </ul>
                <p v-if="!selectedPlan.modules.length && !selectedPlan.features.length" class="mt-3">No included modules or features are listed for this plan.</p>
            </section>
            <label>Description<textarea v-model="form.description" required maxlength="5000" rows="3" placeholder="Describe what this quotation covers" /></label>
            <label>Terms<textarea v-model="form.terms" maxlength="5000" rows="3" /></label>
            <div v-if="preview" class="rounded-lg bg-slate-50 p-4 text-sm text-slate-700">
                <p>Catalogue unit price: {{ selectedPlan.currency }} {{ selectedPlan.price }} / {{ selectedPlan.billing_cycle }}</p>
                <p>Subtotal: {{ selectedPlan.currency }} {{ preview.subtotal }}</p>
                <p>VAT ({{ vatRate }}%): {{ selectedPlan.currency }} {{ preview.tax }}</p>
                <p class="font-bold">Total: {{ selectedPlan.currency }} {{ preview.total }}</p>
            </div>
            <p class="text-sm text-slate-500">The final amount is calculated by the server. Future invoices follow the catalogue and subscription rules in effect when created; a quotation is not an invoice.</p>
            <button class="form-button" :disabled="form.processing || !selectedPlan">Generate quotation</button>
        </form>
        <section>
            <h2 class="mb-4 text-xl font-bold">Your quotations</h2>
            <div class="space-y-3">
                <article v-for="quotation in quotations.data" :key="quotation.uuid" class="flex flex-wrap items-center justify-between gap-4 rounded-xl bg-white p-5">
                    <div>
                        <h3 class="font-bold">{{ quotation.quotation_number }} - {{ quotation.recipient_organization || quotation.recipient_name }}</h3>
                        <p class="mt-1 text-sm text-slate-600">{{ quotation.product_name }} - {{ quotation.plan_name }} · {{ quotation.currency }} {{ quotation.total }}</p>
                    </div>
                    <a :href="route('partner.quotations.pdf', quotation.uuid)" class="rounded-lg bg-[#0B3D6E] px-4 py-2 text-sm font-semibold text-white">Download PDF</a>
                </article>
                <p v-if="!quotations.data.length" class="rounded-xl bg-white p-5 text-slate-600">No quotations created yet.</p>
            </div>
            <Pagination :page="quotations" />
        </section>
    </PartnerLayout>
</template>
