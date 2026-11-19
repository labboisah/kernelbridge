<script setup>
import PartnerLayout from '@/Layouts/PartnerLayout.vue';
import InputError from '@/Components/InputError.vue';
import { useForm } from '@inertiajs/vue3';

const props = defineProps({ bankDetails: Object });
const form = useForm({
    bank_name: props.bankDetails?.bank_name || '',
    account_name: props.bankDetails?.account_name || '',
    account_number: props.bankDetails?.account_number || '',
});
</script>

<template>
    <PartnerLayout title="Bank details">
        <form class="form-card max-w-2xl" @submit.prevent="form.put(route('partner.bank.update'))">
            <p class="text-slate-600">These details are accessible only to you and authorized administrators. Do not send them by email.</p>
            <label for="bank-name">Bank name<input id="bank-name" v-model="form.bank_name" required maxlength="255" autocomplete="organization"/><InputError :message="form.errors.bank_name"/></label>
            <label for="account-name">Account name<input id="account-name" v-model="form.account_name" required maxlength="255" autocomplete="off"/><InputError :message="form.errors.account_name"/></label>
            <label for="account-number">Account number<input id="account-number" v-model="form.account_number" required maxlength="50" autocomplete="off"/><InputError :message="form.errors.account_number"/></label>
            <button class="form-button" :disabled="form.processing">Save bank details</button>
        </form>
    </PartnerLayout>
</template>
