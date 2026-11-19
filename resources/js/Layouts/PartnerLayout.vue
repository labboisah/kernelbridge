<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import PartnerNavigation from '@/Components/Partner/PartnerNavigation.vue';

defineProps({ title: String });
const navigationOpen = ref(false);
</script>
<template>
    <Head :title="title + ' | Partner portal'" />
    <div class="min-h-screen bg-slate-100">
        <header class="bg-[#061a35] text-white shadow">
            <div class="mx-auto flex max-w-7xl items-center justify-between px-6 py-4">
                <Link href="/" class="isolate flex items-center gap-3"><img src="/images/1.png" alt="KernelBridge Technologies" width="438" height="426" class="h-11 w-11 object-cover mix-blend-screen" /><span class="font-semibold">Partners</span></Link>
                <div class="flex items-center gap-3 text-sm"><span class="hidden text-slate-300 sm:inline">{{ $page.props.auth.user.name }}</span><button class="rounded-lg border border-white/20 px-3 py-2 lg:hidden" type="button" :aria-expanded="navigationOpen" aria-controls="partner-sidebar" @click="navigationOpen=!navigationOpen">Menu</button><button class="rounded-lg border border-white/20 px-3 py-2 hover:bg-white/10" @click="router.post(route('logout'))">Sign out</button></div>
            </div>
        </header>
        <div class="mx-auto grid max-w-[90rem] gap-6 px-4 py-6 sm:px-6 lg:grid-cols-[260px_minmax(0,1fr)] lg:py-8">
            <aside id="partner-sidebar" class="rounded-2xl bg-white p-4 shadow-sm lg:sticky lg:top-6 lg:block lg:max-h-[calc(100vh-3rem)] lg:overflow-y-auto" :class="navigationOpen?'block':'hidden'"><nav aria-label="Partner navigation"><PartnerNavigation/></nav></aside>
            <main class="min-w-0"><h1 class="mb-6 text-3xl font-bold">{{ title }}</h1><p v-if="$page.props.flash.success" role="status" class="success-message mb-5">{{ $page.props.flash.success }}</p><p v-if="$page.props.flash.error" role="alert" class="mb-5 rounded-lg bg-red-50 p-4 text-red-800">{{ $page.props.flash.error }}</p><slot/></main>
        </div>
    </div>
</template>
