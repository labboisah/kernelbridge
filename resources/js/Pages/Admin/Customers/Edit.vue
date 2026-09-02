<script setup>
import AdminNavigation from '@/Components/Admin/AdminNavigation.vue'; import CustomerForm from '@/Components/Admin/CustomerForm.vue'; import PortalLayout from '@/Layouts/PortalLayout.vue'; import { Head } from '@inertiajs/vue3'; defineProps({customer:{type:Object,required:true},products:{type:Array,required:true},assignees:{type:Array,required:true}});
</script>
<template><Head :title="`Edit ${customer.name}`"/><PortalLayout area="Administration"><template #navigation><AdminNavigation active="customers"/></template><div class="mb-6"><h1 class="text-3xl font-bold">Edit {{ customer.name }}</h1><p class="mt-2 text-slate-600">{{ customer.customer_number }}</p></div><CustomerForm :customer="customer" :products="products" :assignees="assignees"/></PortalLayout></template>
