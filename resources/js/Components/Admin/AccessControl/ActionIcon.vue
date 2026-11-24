<script setup>
defineProps({ name: { type: String, required: true } });
</script>

<template>
    <svg class="h-4 w-4 shrink-0" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <template v-if="name === 'create'"><path d="M10 3v14M3 10h14" /></template>
        <template v-else-if="name === 'edit'"><path d="m3 14.5-.8 3.3 3.3-.8L16 7.5 12.5 4 3 14.5Z" /><path d="m11.5 5 3.5 3.5" /></template>
        <template v-else-if="name === 'delete'"><path d="M3.5 5.5h13M8 5.5V3.8h4v1.7m-6.5 0 .7 11h7.6l.7-11M8.2 8.5v5.5m3.6-5.5v5.5" /></template>
        <template v-else-if="name === 'save'"><path d="m4 10 4 4 8-8" /><path d="M17 10a7 7 0 1 1-2-5" /></template>
        <template v-else-if="name === 'search'"><circle cx="8.5" cy="8.5" r="5.5" /><path d="m13 13 4 4" /></template>
        <template v-else><path d="M4 10h12m-5-5 5 5-5 5" /></template>
    </svg>
</template>
