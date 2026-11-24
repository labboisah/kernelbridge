<script setup>
defineProps({ attachments: { type: Array, required: true } });
const previewTypes = ['application/pdf', 'text/plain', 'image/jpeg', 'image/png', 'image/webp'];
</script>

<template>
    <div v-if="attachments.length" class="mt-3 space-y-3">
        <div v-for="file in attachments" :key="file.uuid" class="rounded-lg border border-slate-200 p-3">
            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
                <span class="font-semibold text-slate-700">{{ file.original_name }}</span>
                <a :href="route('notifications.attachments.download', file.uuid)" class="font-semibold text-cyan-700 underline">Download</a>
                <a v-if="previewTypes.includes(file.mime_type)" :href="route('notifications.attachments.preview', file.uuid)" target="_blank" rel="noopener noreferrer" class="font-semibold text-cyan-700 underline">Open preview</a>
            </div>
            <details v-if="previewTypes.includes(file.mime_type) && file.mime_type.startsWith('image/')" class="mt-2">
                <summary class="cursor-pointer text-sm font-semibold text-cyan-700">Preview image</summary>
                <img :src="route('notifications.attachments.preview', file.uuid)" :alt="file.original_name" class="mt-3 max-h-96 max-w-full object-contain" />
            </details>
        </div>
    </div>
</template>
