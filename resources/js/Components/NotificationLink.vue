<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const unread = computed(() => usePage().props.auth.unread_notifications || 0);
</script>

<template>
    <Link :href="route('notifications.index')" class="relative rounded-lg border border-white/20 p-2 text-white hover:bg-white/10" :aria-label="`Notifications, ${unread} unread`">
        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-2 7-2 9h16c0-2-2-2-2-9M10 21h4" stroke-linecap="round" stroke-linejoin="round" /></svg>
        <span v-if="unread" class="absolute -right-2 -top-2 rounded-full bg-red-600 px-1.5 text-xs font-bold">{{ unread > 99 ? '99+' : unread }}</span>
    </Link>
</template>
