<script setup>
import { Link } from '@inertiajs/vue3';
defineProps({ types: { type: Object, required: true } });
</script>
<template>
<section id="partners" class="bg-slate-50 py-20">
    <div class="page-shell">
        <p class="section-label">Partner with KernelBridge</p>
        <h2 class="mt-3 text-4xl font-bold text-[#102235]">Find your place in our community</h2>
        <p class="mt-4 max-w-2xl text-lg text-slate-600">Build with us, introduce our products, or explore an investment conversation.</p>
        <div class="mt-10 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            <article v-for="(category, type) in types" :key="type" class="flex flex-col rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <h3 class="text-xl font-bold text-[#0B3D6E]">{{ category.label }}</h3>
                <p class="mb-8 mt-3 flex-1 leading-7 text-slate-600">{{ category.description }}</p>
                <Link :href="route('partners.register', type)" class="rounded-xl bg-[#0B3D6E] px-4 py-3 text-center text-sm font-bold text-white">{{ $page.props.auth.user ? 'Continue onboarding' : 'Register as ' + category.label.toLowerCase() }}</Link>
            </article>
        </div>
    </div>
</section>
</template>
