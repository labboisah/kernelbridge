<script setup>
import { computed, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import CustomerNavigation from '@/Components/Customer/CustomerNavigation.vue';
import InputError from '@/Components/InputError.vue';

const props = defineProps({ customer: Object, products: Array, plans: Array });
const form = useForm({ product_uuid: '', plan_uuid: '', auto_renew: true });
const availablePlans = computed(() => props.plans.filter(plan => plan.product_uuid === form.product_uuid));
const selectedPlan = computed(() => availablePlans.value.find(plan => plan.uuid === form.plan_uuid));
const money = (value, currency) => new Intl.NumberFormat('en-NG', { style: 'currency', currency }).format(Number(value));

watch(() => form.product_uuid, () => { form.plan_uuid = ''; });
</script>

<template>
    <Head title="Add service" />
    <PortalLayout area="Customer Portal">
        <template #navigation><CustomerNavigation active="services" /></template>

        <div class="mb-6">
            <h1 class="text-3xl font-bold">Add a service</h1>
            <p class="mt-2 text-slate-600">Choose a KernelBridge service, then select the plan that fits your requirements.</p>
            <p class="mt-2 text-sm font-semibold text-cyan-800">Account: {{ customer.name }} · {{ customer.customer_number }}</p>
        </div>

        <form class="form-card" @submit.prevent="form.post(route('portal.services.store'))">
            <div class="form-grid">
                <label>
                    Service
                    <select v-model="form.product_uuid" required>
                        <option value="">Select a service</option>
                        <option v-for="product in products" :key="product.uuid" :value="product.uuid">{{ product.name }}</option>
                    </select>
                    <InputError :message="form.errors.product_uuid" />
                </label>

                <label>
                    Plan
                    <select v-model="form.plan_uuid" :disabled="!form.product_uuid" required>
                        <option value="">{{ form.product_uuid ? 'Select a plan' : 'Select a service first' }}</option>
                        <option v-for="plan in availablePlans" :key="plan.uuid" :value="plan.uuid">{{ plan.name }} — {{ plan.billing_cycle }}</option>
                    </select>
                    <InputError :message="form.errors.plan_uuid" />
                </label>
            </div>

            <article v-if="selectedPlan" class="my-6 overflow-hidden rounded-2xl border border-cyan-200 bg-cyan-50">
                <div class="border-b border-cyan-200 p-5">
                    <p class="text-sm font-bold uppercase tracking-wider text-cyan-700">Selected plan</p>
                    <div class="mt-1 flex flex-wrap items-end justify-between gap-3">
                        <div><h2 class="text-xl font-bold text-[#0B3D6E]">{{ selectedPlan.name }}</h2><p v-if="selectedPlan.description" class="mt-1 text-sm text-slate-600">{{ selectedPlan.description }}</p></div>
                        <p class="text-xl font-bold">{{ money(selectedPlan.price, selectedPlan.currency) }} <span class="text-sm font-medium text-slate-500">/ {{ selectedPlan.billing_cycle }}</span></p>
                    </div>
                </div>
                <div class="p-5">
                    <h3 class="font-bold text-slate-900">Plan features</h3>
                    <ul v-if="selectedPlan.features.length" class="mt-3 grid gap-3 sm:grid-cols-2">
                        <li v-for="feature in selectedPlan.features" :key="feature.key" class="flex items-start gap-2 text-sm" :class="feature.enabled ? 'text-slate-700' : 'text-slate-400'">
                            <span class="mt-0.5 font-bold" :class="feature.enabled ? 'text-emerald-600' : 'text-slate-400'">{{ feature.enabled ? '✓' : '–' }}</span>
                            <span>{{ feature.name }}<strong v-if="feature.limit" class="ml-1">({{ feature.limit }})</strong></span>
                        </li>
                    </ul>
                    <p v-else class="mt-2 text-sm text-slate-500">No individual features have been published for this plan.</p>
                    <ul v-if="selectedPlan.limits.length" class="mt-4 grid gap-2 sm:grid-cols-2">
                        <li v-for="limit in selectedPlan.limits" :key="limit.key" class="rounded-lg bg-white px-3 py-2 text-sm"><span class="text-slate-500">{{ limit.name }}:</span> <strong>{{ limit.value || 'Unlimited' }}</strong></li>
                    </ul>
                </div>
            </article>

            <p v-else-if="form.product_uuid && !availablePlans.length" class="my-5 rounded-xl bg-amber-50 p-4 text-sm text-amber-800">No public plans are currently available for this service. Please contact sales for a tailored quotation.</p>

            <label class="flex items-center gap-3"><input v-model="form.auto_renew" type="checkbox" class="rounded border-slate-300 text-[#0797A5] focus:ring-[#0797A5]" /><span>Automatically renew this service</span></label>
            <div class="mt-6 flex gap-3"><button class="form-button" :disabled="form.processing || !selectedPlan">Create service request</button><Link :href="route('portal.services.index')" class="rounded-xl border px-6 py-3">Cancel</Link></div>
        </form>
    </PortalLayout>
</template>
