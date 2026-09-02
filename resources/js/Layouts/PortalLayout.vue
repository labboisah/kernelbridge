<script setup>
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AdminNavigation from '@/Components/Admin/AdminNavigation.vue';
import CustomerNavigation from '@/Components/Customer/CustomerNavigation.vue';
import StaffNavigation from '@/Components/Staff/StaffNavigation.vue';

const props = defineProps({ area: { type: String, required: true } });
const navigationOpen = ref(false);
const isAdmin = props.area === 'Administration';
const roles = computed(() => usePage().props.auth.roles || []);
const isStaff = computed(() => roles.value.some(role => ['sales', 'finance', 'support'].includes(role)) && !roles.value.some(role => ['super_admin', 'admin'].includes(role)));
</script>

<template>
    <div class="min-h-screen bg-slate-100">
        <header class="bg-[#061a35] text-white shadow">
            <div class="mx-auto flex max-w-7xl items-center justify-between px-6 py-4">
                <Link href="/" class="isolate flex items-center gap-3"><img src="/images/1.png" alt="KernelBridge Technologies" width="438" height="426" class="h-11 w-11 object-cover mix-blend-screen" /><span class="font-semibold">{{ area }}</span></Link>
                <div class="flex items-center gap-3 text-sm"><span class="hidden text-slate-300 sm:inline">{{ $page.props.auth.user.name }}</span><button class="rounded-lg border border-white/20 px-3 py-2 lg:hidden" type="button" :aria-expanded="navigationOpen" aria-controls="portal-sidebar" @click="navigationOpen=!navigationOpen">Menu</button><button class="rounded-lg border border-white/20 px-3 py-2 hover:bg-white/10" @click="router.post(route('logout'))">Sign out</button></div>
            </div>
        </header>
        <div class="mx-auto grid max-w-[90rem] gap-6 px-4 py-6 sm:px-6 lg:grid-cols-[260px_minmax(0,1fr)] lg:py-8">
            <aside id="portal-sidebar" class="rounded-2xl bg-white p-4 shadow-sm lg:sticky lg:top-6 lg:block lg:max-h-[calc(100vh-3rem)] lg:overflow-y-auto" :class="navigationOpen?'block':'hidden'"><nav aria-label="Portal navigation"><StaffNavigation v-if="isStaff"/><AdminNavigation v-else-if="isAdmin"/><CustomerNavigation v-else/><slot name="extra-navigation"/></nav></aside>
            <main class="min-w-0"><slot /></main>
        </div>
    </div>
</template>
