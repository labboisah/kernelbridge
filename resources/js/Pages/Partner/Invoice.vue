<script setup>
import CustomerView from '@/Pages/Invoices/CustomerView.vue';

defineProps({
    invoice: {
        type: Object,
        required: true,
    },
    documentAssets: {
        type: Object,
        required: true,
    },
    canPayOnline: {
        type: Boolean,
        default: false,
    },
});
</script>

<template>
    <CustomerView
        :invoice="invoice"
        :document-assets="documentAssets"
        :can-pay-online="canPayOnline"
        :partner="true"
    />
</template>
