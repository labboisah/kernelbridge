<script setup>
import AdminNavigation from '@/Components/Admin/AdminNavigation.vue'; import CustomerForm from '@/Components/Admin/CustomerForm.vue'; import PortalLayout from '@/Layouts/PortalLayout.vue'; import { Head } from '@inertiajs/vue3'; defineProps({products:{type:Array,required:true},assignees:{type:Array,required:true}});
</script>
<template><Head title="Create customer"/><PortalLayout area="Administration"><template #navigation><AdminNavigation active="customers"/></template><div class="mb-6"><h1 class="text-3xl font-bold">Create customer</h1><p class="mt-2 text-slate-600">Create an individual or organisation customer record.</p></div><CustomerForm :products="products" :assignees="assignees"/></PortalLayout></template>
