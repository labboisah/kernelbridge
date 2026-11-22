<script setup>
import PartnerLayout from '@/Layouts/PartnerLayout.vue';
import FormErrors from '@/Components/Partner/FormErrors.vue';
import { Link, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({ customer: Object, products: Array, plans: Array });
const showAll = ref(props.customer.interested_product_ids.length === 0);
const visibleProducts = computed(() => showAll.value
    ? props.products
    : props.products.filter(product => props.customer.interested_product_ids.includes(product.id)));
const selectedProduct = ref('');
const form = useForm({ customer_uuid: props.customer.uuid, plan_uuid: '' });
const visiblePlans = computed(() => props.plans.filter(plan =>
    visibleProducts.value.some(product => product.id === plan.product_id)
    && (!selectedProduct.value || plan.product_id === selectedProduct.value)));
watch([showAll, selectedProduct], () => {
    if (!visiblePlans.value.some(plan => plan.uuid === form.plan_uuid)) form.plan_uuid = '';
});
watch(showAll, () => {
    if (!visibleProducts.value.some(product => product.id === selectedProduct.value)) selectedProduct.value = '';
});
const productName = productId => props.products.find(product => product.id === productId)?.name || '';
const submit = () => form.post(route('partner.invoices.store'));
</script>

<template>
    <PartnerLayout title="Add subscription">
        <Link :href="route('partner.customers')" class="mb-5 inline-block font-semibold text-cyan-700">Back to clients</Link>
        <form class="form-card" @submit.prevent="submit">
            <h2 class="text-xl font-bold">Add subscription for {{ customer.name }}</h2>
            <p class="text-sm text-slate-600">Choose one plan to prepare an invoice. Payment must be verified before the subscription activates.</p>
            <FormErrors :errors="form.errors" />
            <label v-if="customer.interested_product_ids.length" class="!flex items-center gap-2">
                <input v-model="showAll" type="checkbox" class="!mt-0" />
                <span>Show all products instead of only the client's products of interest</span>
            </label>
            <label>Product
                <select v-model="selectedProduct">
                    <option value="">All shown products</option>
                    <option v-for="product in visibleProducts" :key="product.id" :value="product.id">{{ product.name }}</option>
                </select>
            </label>
            <label>Subscription plan
                <select v-model="form.plan_uuid" required>
                    <option value="">Choose a plan</option>
                    <option v-for="plan in visiblePlans" :key="plan.uuid" :value="plan.uuid">
                        {{ productName(plan.product_id) }} - {{ plan.name }} - {{ plan.currency }} {{ plan.price }} / {{ plan.billing_cycle }}
                    </option>
                </select>
            </label>
            <p v-if="!visiblePlans.length" class="text-sm text-slate-600">No available subscription plans for the products shown. Show all products or contact the administration team.</p>
            <p class="text-sm text-slate-500">Catalogue prices exclude VAT. VAT of {{ Number($page.props.billing.vat_rate) }}% is added to the invoice and shown separately.</p>
            <button class="form-button" :disabled="form.processing || !visiblePlans.length">Create invoice</button>
        </form>
    </PartnerLayout>
</template>
