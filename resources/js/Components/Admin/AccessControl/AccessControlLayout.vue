<script setup>
import AdminNavigation from '@/Components/Admin/AdminNavigation.vue';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    active: { type: String, required: true },
    title: { type: String, required: true },
    description: { type: String, required: true },
});
const page = usePage();
const pageError = computed(() => Object.values(page.props.errors || {}).flat().join(' '));
</script>

<template>
    <Head :title="props.title" />
    <PortalLayout area="Administration">
        <template #navigation><AdminNavigation :active="props.active" /></template>
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="text-sm font-bold uppercase text-cyan-700">Super admin · Access control</p>
                <h1 class="mt-1 text-3xl font-bold">{{ props.title }}</h1>
                <p class="mt-2 text-slate-600">{{ props.description }}</p>
            </div>
            <slot name="actions" />
        </div>
        <p v-if="$page.props.flash.success" role="status" class="success-message mt-5">{{ $page.props.flash.success }}</p>
        <p v-if="pageError" role="alert" class="mt-5 rounded-xl bg-red-50 p-4 text-sm font-semibold text-red-800">{{ pageError }}</p>
        <slot />
    </PortalLayout>
</template>
