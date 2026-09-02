<script setup>
import ProductForm from '@/Components/Admin/ProductForm.vue'; import PortalLayout from '@/Layouts/PortalLayout.vue'; import { Head, Link } from '@inertiajs/vue3';
</script>
<template><Head title="Create product"/><PortalLayout area="Administration"><template #navigation><Link :href="route('admin.dashboard')" class="block rounded-lg px-3 py-2 text-slate-600">Overview</Link><Link :href="route('admin.products.index')" class="block rounded-lg bg-cyan-50 px-3 py-2 font-medium text-cyan-800">Products</Link></template><div class="mb-6"><h1 class="text-3xl font-bold text-slate-900">Create product</h1><p class="mt-2 text-slate-600">Add software to the commercial catalogue.</p></div><ProductForm/></PortalLayout></template>
