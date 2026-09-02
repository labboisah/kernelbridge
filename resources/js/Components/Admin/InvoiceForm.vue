<script setup>
import InputError from '@/Components/InputError.vue';
import { Link, useForm } from '@inertiajs/vue3';

const props = defineProps({ invoice: { type: Object, default: null }, customers: Array, products: Array, plans: Array });
const form = useForm({
    customer_id: props.invoice?.customer_id || '', quotation_id: props.invoice?.quotation_id || '',
    issue_date: props.invoice?.issue_date?.slice(0, 10) || new Date().toISOString().slice(0, 10),
    due_date: props.invoice?.due_date?.slice(0, 10) || '', currency: props.invoice?.currency || 'NGN',
    notes: props.invoice?.notes || '', items: (props.invoice?.items || []).map(item => ({
        product_id: item.product_id || '', plan_id: item.plan_id || '', description: item.description,
        quantity: item.quantity, unit_price: item.unit_price, discount: item.discount, tax: item.tax,
    })),
});
const addItem = () => form.items.push({ product_id: '', plan_id: '', description: '', quantity: 1, unit_price: 0, discount: 0, tax: 0 });
if (!form.items.length) addItem();
const submit = () => props.invoice ? form.put(route('admin.invoices.update', props.invoice.uuid)) : form.post(route('admin.invoices.store'));
</script>

<template>
    <form class="form-card" @submit.prevent="submit">
        <div class="form-grid">
            <label>Customer<select v-model="form.customer_id" required><option value="">Select customer</option><option v-for="customer in customers" :key="customer.id" :value="customer.id">{{ customer.customer_number }} — {{ customer.name }}</option></select><InputError :message="form.errors.customer_id" /></label>
            <label>Currency<input v-model="form.currency" class="uppercase" maxlength="3" required /><InputError :message="form.errors.currency" /></label>
            <label>Issue date<input v-model="form.issue_date" type="date" required /></label>
            <label>Due date<input v-model="form.due_date" type="date" required /><InputError :message="form.errors.due_date" /></label>
        </div>
        <section class="space-y-4">
            <div class="flex items-center justify-between"><h2 class="text-xl font-bold">Line items</h2><button type="button" class="form-secondary-button" @click="addItem">Add item</button></div>
            <div v-for="(item, index) in form.items" :key="index" class="rounded-xl border p-4">
                <div class="form-grid">
                    <label>Product<select v-model="item.product_id"><option value="">Custom</option><option v-for="product in products" :key="product.id" :value="product.id">{{ product.name }}</option></select></label>
                    <label>Plan<select v-model="item.plan_id"><option value="">None</option><option v-for="plan in plans" :key="plan.id" :value="plan.id">{{ plan.name }}</option></select></label>
                    <label>Description<input v-model="item.description" required /><InputError :message="form.errors[`items.${index}.description`]" /></label>
                    <label>Quantity<input v-model="item.quantity" type="number" min="0.01" step="0.01" required /></label>
                    <label>Unit price<input v-model="item.unit_price" type="number" min="0" step="0.01" required /></label>
                    <label>Discount amount<input v-model="item.discount" type="number" min="0" step="0.01" required /></label>
                    <label>Tax amount<input v-model="item.tax" type="number" min="0" step="0.01" required /></label>
                </div>
                <button v-if="form.items.length > 1" type="button" class="text-sm font-bold text-red-600" @click="form.items.splice(index, 1)">Remove</button>
            </div>
        </section>
        <label>Notes<textarea v-model="form.notes" rows="4" /></label>
        <p class="text-sm text-slate-500">Totals and balances are calculated exclusively by the server.</p>
        <div class="flex gap-3"><button class="form-button" :disabled="form.processing">{{ invoice ? 'Save invoice' : 'Create invoice' }}</button><Link :href="route('admin.invoices.index')" class="rounded-xl border px-6 py-3">Cancel</Link></div>
    </form>
</template>
