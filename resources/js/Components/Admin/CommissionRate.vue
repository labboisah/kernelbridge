<script setup>
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import InputError from '@/Components/InputError.vue';
const props = defineProps({ product: { type: Object, required: true } });
const fields = [['vat_commission_rate','VAT'],['developer_commission_rate','Developer'],['platform_commission_rate','Platform (individual partners)'],['staff_commission_rate','Staff'],['partner_commission_rate','Individual partner']];
const form = useForm(Object.fromEntries([...fields.map(([key]) => [key, props.product[key]]), ['organization_partner_commission_rate', props.product.organization_partner_commission_rate]]));
const total = computed(() => fields.reduce((sum,[key]) => sum + Math.round(Number(form[key] || 0) * 100), 0));
const organizationPlatform = computed(() => Number(form.platform_commission_rate || 0) + Number(form.partner_commission_rate || 0) - Number(form.organization_partner_commission_rate || 0));
const organizationTotal = computed(() => total.value - Math.round(Number(form.platform_commission_rate || 0) * 100) - Math.round(Number(form.partner_commission_rate || 0) * 100) + Math.round(organizationPlatform.value * 100) + Math.round(Number(form.organization_partner_commission_rate || 0) * 100));
const valid = computed(() => total.value === 10000 && fields.every(([key]) => form[key] !== '' && Number.isFinite(Number(form[key])) && Number(form[key]) >= 0 && Number(form[key]) <= 100)
    && form.organization_partner_commission_rate !== '' && Number.isFinite(Number(form.organization_partner_commission_rate))
    && Number(form.organization_partner_commission_rate) >= 0 && Number(form.organization_partner_commission_rate) <= 100
    && organizationPlatform.value >= 0 && organizationTotal.value === 10000);
</script>
<template><form class="form-card" @submit.prevent="form.put(route('admin.partners.commissions.update',product.slug),{preserveScroll:true})">
    <h2 class="text-xl font-bold">{{ product.name }} commission shares</h2>
    <div class="form-grid"><label v-for="[key,label] in fields" :key="key" :for="key">{{ label }} (%)<input :id="key" v-model="form[key]" type="number" min="0" max="100" step="0.01" required/><InputError :message="form.errors[key]"/></label></div>
    <p role="status" class="rounded-lg p-4 font-bold" :class="valid?'bg-emerald-50 text-emerald-800':'bg-amber-50 text-amber-800'">Total allocation: {{ (total/100).toFixed(2) }}% / 100%<span v-if="!valid" class="mt-1 block text-sm font-normal">Adjust the shares to total exactly 100%.</span></p>
    <label for="organization-partner-rate">Organization partner (%)<input id="organization-partner-rate" v-model="form.organization_partner_commission_rate" type="number" min="0" max="100" step="0.01" required/><InputError :message="form.errors.organization_partner_commission_rate"/></label>
    <p v-if="organizationPlatform < 0" role="alert" class="text-sm text-red-700">Organization rate exceeds the available platform and individual partner shares.</p>
    <p class="rounded-lg bg-slate-50 p-4 text-sm">For organization partners, Platform is adjusted to {{ organizationPlatform.toFixed(2) }}% so the allocation remains 100%. Organization allocation total: {{ (organizationTotal / 100).toFixed(2) }}% / 100%.</p>
    <InputError :message="form.errors.allocation"/>
    <button class="form-button" :disabled="form.processing || !valid">{{ form.processing?'Saving...':'Save product commissions' }}</button>
</form></template>
