<script setup>
import PortalLayout from '@/Layouts/PortalLayout.vue';
import InputError from '@/Components/InputError.vue';
import {Head,useForm} from '@inertiajs/vue3';
const props=defineProps({settings:Object});
const form=useForm({vat_rate:props.settings.vat_rate});
</script>
<template><Head title="VAT settings"/><PortalLayout area="Administration"><h1 class="text-3xl font-bold">VAT settings</h1><p class="my-5 text-slate-600">Set the VAT percentage added to new invoices after discounts. Checkout and plan prices show this rate. Existing invoices, including drafts, retain their recorded rate.</p><p v-if="$page.props.flash.success" role="status" class="success-message mb-5">{{ $page.props.flash.success }}</p><form class="form-card max-w-xl" @submit.prevent="form.put(route('admin.billing.update'),{preserveScroll:true})"><label for="vat-rate">VAT rate (%)<input id="vat-rate" v-model="form.vat_rate" type="number" min="0" max="100" step="0.01" required/><InputError :message="form.errors.vat_rate"/></label><p class="text-sm text-slate-500">Enter a percentage from 0 to 100, with up to two decimal places. Use 0% when no VAT should be added to new invoices.</p><button class="form-button" :disabled="form.processing">{{ form.processing?'Saving...':'Save VAT rate' }}</button></form></PortalLayout></template>
