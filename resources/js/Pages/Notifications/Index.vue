<script setup>
import PartnerLayout from '@/Layouts/PartnerLayout.vue';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import Pagination from '@/Components/Partner/Pagination.vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

defineProps({ notifications: Object });
const page = usePage();
const roles = computed(() => page.props.auth.roles || []);
const administration = computed(() => roles.value.some(role => ['super_admin', 'admin'].includes(role)));
const staff = computed(() => roles.value.some(role => ['sales', 'finance', 'support'].includes(role)));
const partner = computed(() => !administration.value && !staff.value && page.props.auth.is_partner);
const layout = computed(() => partner.value ? PartnerLayout : PortalLayout);
const area = computed(() => administration.value ? 'Administration' : staff.value ? 'Staff Workspace' : 'Customer portal');
const safeAction = value => {
    if (typeof value !== 'string') return null;
    try {
        const url = new URL(value, window.location.origin);
        return url.origin === window.location.origin && ['http:', 'https:'].includes(url.protocol) ? url.href : null;
    } catch {
        return null;
    }
};
</script>

<template>
    <Head title="Notifications" />
    <component :is="layout" :title="'Notifications'" :area="area">
        <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
            <h1 v-if="!partner" class="text-3xl font-bold text-slate-900">Notifications</h1>
            <button v-if="$page.props.auth.unread_notifications" type="button" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold" @click="router.put(route('notifications.read-all'), {}, { preserveScroll: true })">Mark all as read</button>
        </div>
        <div class="space-y-3">
            <article v-for="notification in notifications.data" :key="notification.id" class="rounded-xl bg-white p-5 shadow-sm" :class="!notification.read_at ? 'border-l-4 border-cyan-600' : ''">
                <div class="flex flex-wrap justify-between gap-3">
                    <h2 class="font-bold text-slate-900">{{ notification.data.subject || 'Notification' }}</h2>
                    <span class="text-xs text-slate-500">{{ new Date(notification.created_at).toLocaleString() }}</span>
                </div>
                <div v-if="notification.formatted_message" class="notification-message mt-2 text-sm text-slate-700" v-html="notification.formatted_message"></div>
                <p v-else class="mt-2 whitespace-pre-wrap text-sm text-slate-700">{{ notification.data.message }}</p>
                <div v-if="notification.attachments?.length" class="mt-3 flex flex-wrap gap-3">
                    <a v-for="file in notification.attachments" :key="file.uuid" :href="route('notifications.attachments.download', file.uuid)" class="text-sm font-semibold text-cyan-700 underline">{{ file.original_name }}</a>
                </div>
                <div class="mt-3 flex flex-wrap gap-4 text-sm">
                    <a v-if="safeAction(notification.data.action_url)" :href="safeAction(notification.data.action_url)" class="font-semibold text-cyan-700">View details</a>
                    <button v-if="!notification.read_at" type="button" class="font-semibold text-cyan-700" @click="router.put(route('notifications.read', notification.id), {}, { preserveScroll: true })">Mark as read</button>
                </div>
            </article>
            <p v-if="!notifications.data.length" class="rounded-xl bg-white p-5 text-slate-600">No notifications yet.</p>
        </div>
        <Pagination :page="notifications" />
    </component>
</template>
