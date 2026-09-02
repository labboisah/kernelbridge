<script setup>
import CTASection from '@/Components/Public/CTASection.vue';
import FeatureCard from '@/Components/Public/FeatureCard.vue';
import SeoHead from '@/Components/Public/SeoHead.vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { Link } from '@inertiajs/vue3';

defineProps({ product: { type: Object, required: true } });
</script>

<template>
    <SeoHead :title="product.seo_title || `${product.name} | KernelBridge Technologies`" :description="product.seo_description || product.hero_description || product.description" :canonical="`${$page.props.app.url}/products/${product.slug}`" />
    <PublicLayout>
        <section class="page-hero">
            <div class="page-shell">
                <p class="section-label !text-teal-300">{{ product.industry }}</p>
                <h1 class="page-title">{{ product.name }}</h1>
                <h2 class="mt-5 max-w-4xl text-2xl font-bold text-white sm:text-3xl">{{ product.tagline }}</h2>
                <p class="page-intro">{{ product.hero_description || product.description }}</p>
                <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                    <a v-if="product.demo_url" :href="product.demo_url" target="_blank" rel="noopener noreferrer" class="rounded-xl bg-[#0797A5] px-6 py-3.5 text-center font-bold text-white">Try Demo</a>
                    <Link :href="route('demo', { product: product.slug })" class="rounded-xl border border-white/40 px-6 py-3.5 text-center font-bold text-white hover:bg-white/10">Request Implementation</Link>
                    <Link :href="route('products.pricing', product.slug)" class="rounded-xl border border-white/40 px-6 py-3.5 text-center font-bold text-white hover:bg-white/10">View Pricing</Link>
                </div>
            </div>
        </section>

        <section class="page-shell grid gap-14 py-20 lg:grid-cols-2">
            <div><p class="section-label">The platform</p><h2 class="section-title">Built for {{ product.industry.toLowerCase() }}</h2><p class="section-copy">{{ product.description }}</p></div>
            <div class="rounded-3xl border border-slate-200 bg-white p-8 shadow-sm"><h2 class="text-xl font-bold text-[#0B3D6E]">Core capabilities</h2><ul class="mt-6 grid gap-3 sm:grid-cols-2"><li v-for="feature in product.features" :key="feature" class="flex gap-3 text-sm text-slate-700"><span class="text-[#0797A5]">✓</span>{{ feature }}</li></ul></div>
        </section>

        <section v-if="product.benefits?.length" class="bg-white py-20">
            <div class="page-shell"><p class="section-label">Benefits</p><h2 class="section-title">A more connected hotel operation</h2><div class="mt-10 grid gap-8 sm:grid-cols-2 lg:grid-cols-3"><FeatureCard v-for="benefit in product.benefits" :key="benefit.title" v-bind="benefit" /></div></div>
        </section>

        <section v-if="product.workflow?.length" class="bg-[#071426] py-20 text-white">
            <div class="page-shell"><p class="section-label !text-teal-300">How {{ product.name }} works</p><h2 class="mt-3 text-3xl font-bold sm:text-4xl">From reservation to secure checkout</h2><ol class="mt-10 grid gap-3 sm:grid-cols-2 lg:grid-cols-5"><li v-for="(step,index) in product.workflow" :key="step" class="relative rounded-2xl border border-white/15 bg-white/5 p-5"><span class="text-xs font-bold text-teal-300">{{ String(index+1).padStart(2,'0') }}</span><p class="mt-2 font-bold">{{ step }}</p><span v-if="index < product.workflow.length-1" class="absolute -bottom-4 left-1/2 z-10 text-teal-300 sm:hidden" aria-hidden="true">↓</span></li></ol></div>
        </section>

        <section v-if="product.platform_components?.length" class="page-shell py-20"><p class="section-label">Platform components</p><h2 class="section-title">Built for Complete Hotel Operations</h2><div class="mt-10 grid gap-6 md:grid-cols-2 lg:grid-cols-3"><article v-for="component in product.platform_components" :key="component.title" class="rounded-2xl border border-slate-200 bg-white p-7 shadow-sm"><h3 class="text-xl font-bold text-[#0B3D6E]">{{ component.title }}</h3><p class="mt-3 leading-7 text-slate-600">{{ component.description }}</p></article></div></section>

        <CTASection :title="`See ${product.name} in action`" message="Book a focused conversation and product walkthrough with our team." />
    </PublicLayout>
</template>
