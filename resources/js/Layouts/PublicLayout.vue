<script setup>
import Navbar from '@/Components/Public/Navbar.vue';
import Footer from '@/Components/Public/Footer.vue';
defineProps({ overlayNavigation: { type: Boolean, default: false } });
</script>

<template>
    <div class="min-h-screen bg-[#F8FAFC] text-slate-900">
        <a href="#main-content" class="sr-only z-[100] rounded bg-white px-4 py-2 focus:not-sr-only focus:fixed focus:left-4 focus:top-4">Skip to content</a>
        <Navbar :overlay="overlayNavigation" />
        <main id="main-content"><slot /></main>
        <Footer />
    </div>
</template>
