<script setup>
import PublicLayout from '@/Layouts/PublicLayout.vue';
import PartnerSection from '@/Components/Public/PartnerSection.vue';
import { Head, Link } from '@inertiajs/vue3';
defineProps({ types: Object });
</script>
<template><Head title="Partner with KernelBridge"/><PublicLayout>
    <section class="page-hero"><div class="page-shell"><p class="section-label !text-teal-300">Work with us</p><h1 class="page-title">One platform. Three ways to connect.</h1><p class="page-intro">Choose your path, create an account and tell us about your goals. Already registered? Sign in to resume your application.</p></div></section>
    <PartnerSection :types="types"/><div class="page-shell pb-10"><p class="text-slate-600">Looking to buy software for your own organisation? <Link :href="route('register')" class="font-bold text-[#0B3D6E]">Create a customer account</Link>.</p><Link v-if="$page.props.auth.is_partner" :href="route('partner.dashboard')" class="mt-4 inline-block font-bold text-[#0B3D6E]">Open partner portal</Link></div>
    <section class="page-shell pb-16"><h2 class="text-2xl font-bold">How onboarding works</h2><ol class="mt-6 grid gap-6 md:grid-cols-3"><li><strong>1. Create your account</strong><p class="mt-2 text-slate-600">Create your partner login with your email address and a password.</p></li><li><strong>2. Tell us about yourself</strong><p class="mt-2 text-slate-600">Complete your profile and partnership interests. Save and return whenever you need.</p></li><li><strong>3. Submit for review</strong><p class="mt-2 text-slate-600">Our team reviews your application. Return to your onboarding page for the decision and next steps.</p></li></ol><Link :href="route('login')" class="mt-8 inline-block font-bold text-[#0B3D6E]">Already have an account? Sign in</Link></section>
</PublicLayout></template>
