<script setup>
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AdminNavigation from '@/Components/Admin/AdminNavigation.vue';
import CustomerNavigation from '@/Components/Customer/CustomerNavigation.vue';
import StaffNavigation from '@/Components/Staff/StaffNavigation.vue';
import NotificationLink from '@/Components/NotificationLink.vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';

const props = defineProps({ area: { type: String, required: true } });
const navigationOpen = ref(false);
const isAdmin = props.area === 'Administration';
const roles = computed(() => usePage().props.auth.roles || []);
const isStaff = computed(() => roles.value.some(role => ['sales', 'finance', 'support'].includes(role)) && !roles.value.some(role => ['super_admin', 'admin'].includes(role)));
const showAdminAccountMenu = computed(() => isAdmin && roles.value.some(role => ['super_admin', 'admin'].includes(role)));
const photoUrl = computed(() => usePage().props.auth.admin_photo_url);
const photoFailed = ref(false);
watch(photoUrl, () => { photoFailed.value = false; });
</script>

<template>
    <div class="account-inputs min-h-screen bg-slate-100">
        <header class="bg-[#061a35] text-white shadow print:hidden">
            <div class="mx-auto flex max-w-7xl items-center justify-between px-6 py-4">
                <Link href="/" class="isolate flex items-center gap-3"><img src="/images/1.png" alt="KernelBridge Technologies" width="438" height="426" class="h-11 w-11 object-cover mix-blend-screen" /><span class="font-semibold">{{ area }}</span></Link>
                <div class="flex items-center gap-3 text-sm">
                    <NotificationLink />
                    <span v-if="!showAdminAccountMenu" class="hidden text-slate-300 sm:inline">{{ $page.props.auth.user.name }}</span>
                    <button class="rounded-lg border border-white/20 px-3 py-2 lg:hidden" type="button" :aria-expanded="navigationOpen" aria-controls="portal-sidebar" @click="navigationOpen=!navigationOpen">Menu</button>
                    <Dropdown v-if="showAdminAccountMenu" align="right">
                        <template #trigger="{ open }">
                            <button type="button" aria-label="Account menu" aria-haspopup="true" :aria-expanded="open" class="flex h-11 w-11 items-center justify-center overflow-hidden rounded-full border border-white/30 bg-slate-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white">
                                <img v-if="photoUrl && !photoFailed" :src="photoUrl" alt="" class="h-full w-full object-cover" @error="photoFailed = true" />
                                <svg v-else class="h-6 w-6 text-slate-200" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="8" r="4" /><path d="M4 22a8 8 0 0 1 16 0" /></svg>
                            </button>
                        </template>
                        <template #content>
                            <DropdownLink :href="route('profile.edit')">Profile</DropdownLink>
                            <DropdownLink :href="route('logout')" method="post" as="button">Sign out</DropdownLink>
                        </template>
                    </Dropdown>
                    <button v-else class="rounded-lg border border-white/20 px-3 py-2 hover:bg-white/10" @click="router.post(route('logout'))">Sign out</button>
                </div>
            </div>
        </header>
        <div class="mx-auto grid max-w-[90rem] gap-6 px-4 py-6 sm:px-6 lg:grid-cols-[260px_minmax(0,1fr)] lg:py-8 print:block print:p-0">
            <aside id="portal-sidebar" class="rounded-2xl bg-white p-4 shadow-sm lg:sticky lg:top-6 lg:block lg:max-h-[calc(100vh-3rem)] lg:overflow-y-auto print:!hidden" :class="navigationOpen?'block':'hidden'"><nav aria-label="Portal navigation"><StaffNavigation v-if="isStaff"/><AdminNavigation v-else-if="isAdmin"/><CustomerNavigation v-else/><slot name="extra-navigation"/></nav></aside>
            <main class="min-w-0"><slot /></main>
        </div>
    </div>
</template>
