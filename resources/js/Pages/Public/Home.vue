<script setup>
import HeroSection from '@/Components/Public/HeroSection.vue';
import ProductCard from '@/Components/Public/ProductCard.vue';
import SeoHead from '@/Components/Public/SeoHead.vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { Link } from '@inertiajs/vue3';

defineProps({ products: { type: Array, required: true }, activeProductCount: { type: Number, required: true } });

const services = [
    { number: '01', title: 'Custom Software Development', description: "Purpose-built systems around your organisation's workflow, reporting and operational requirements." },
    { number: '02', title: 'Demo & Licensing', description: 'Controlled demo access, subscription management and licence activation for our commercial products.' },
    { number: '03', title: 'Deployment & Training', description: 'Structured implementation, onboarding and practical user training that helps teams adopt the system quickly.' },
    { number: '04', title: 'Support & Improvement', description: 'Ongoing technical support, maintenance and product improvement as your operation evolves.' },
];
</script>

<template>
    <SeoHead title="KernelBridge Technologies LTD" description="KernelBridge Technologies LTD builds practical software platforms for education, healthcare, hospitality, assessment and business operations." canonical="https://kernelbridge.com/" />
    <PublicLayout overlay-navigation>
        <HeroSection eyebrow="Software • Automation • Digital Transformation" title="Bridging Ideas to Smart Digital Solutions" message="KernelBridge Technologies LTD builds practical software platforms for education, healthcare, hospitality, assessment and business operations." />

        <section id="products" class="bg-[#F7FBFD] py-20 sm:py-24">
            <div class="page-shell">
                <div class="mx-auto max-w-3xl text-center">
                    <p class="section-label">Our Core Products</p>
                    <h2 class="mt-3 text-4xl font-bold tracking-[-.04em] text-[#102235] sm:text-5xl">Built for real operational needs</h2>
                    <p class="mt-5 text-lg text-[#637286]">Explore focused products designed for organisations that need reliable, usable and scalable digital systems.</p>
                </div>
                <div class="mx-auto mt-12 grid max-w-6xl gap-5 md:grid-cols-2 lg:grid-cols-6">
                    <div v-for="(product,index) in products" :key="product.id" class="h-full lg:col-span-2" :class="products.length % 3 === 2 && index === products.length - 2 ? 'lg:col-start-2' : ''"><ProductCard :product="product" /></div>
                </div>
            </div>
        </section>

        <section class="bg-[#041225] py-20 text-white sm:py-24">
            <div class="page-shell">
                <div class="mx-auto max-w-3xl text-center">
                    <p class="section-label !text-[#55D8F5]">How We Help</p>
                    <h2 class="mt-3 text-4xl font-bold tracking-[-.04em] sm:text-5xl">From idea to dependable deployment</h2>
                    <p class="mt-5 text-lg text-slate-300">We support the complete software journey—from product design and implementation to licensing, training and ongoing improvement.</p>
                </div>
                <div class="mt-12 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                    <article v-for="service in services" :key="service.number" class="rounded-[20px] border border-cyan-300/15 bg-gradient-to-b from-[#0B2D50]/70 to-[#05182E]/70 px-6 py-8">
                        <span class="text-xs font-black tracking-[.15em] text-[#16B8E8]">{{ service.number }}</span>
                        <h3 class="mt-8 text-xl font-bold leading-tight">{{ service.title }}</h3>
                        <p class="mt-3 text-sm leading-7 text-[#AFC4D5]">{{ service.description }}</p>
                    </article>
                </div>
            </div>
        </section>

        <section class="bg-white py-20 sm:py-24">
            <div class="page-shell grid items-center gap-14 lg:grid-cols-[1.25fr_.75fr]">
                <div>
                    <p class="section-label">KernelBridge Technologies LTD</p>
                    <h2 class="mt-3 max-w-3xl text-4xl font-bold leading-[1.08] tracking-[-.04em] text-[#102235] sm:text-5xl">Technology that connects people, processes and progress.</h2>
                    <p class="mt-6 max-w-3xl text-lg leading-8 text-[#637286]">We build digital systems around practical organisational needs. Our approach combines software engineering, workflow automation, implementation support and clear product ownership so clients can focus on their core work.</p>
                    <div class="mt-9 grid gap-5 sm:grid-cols-3">
                        <div class="border-t-2 border-[#e1edf2] pt-4"><strong class="block text-3xl text-[#0B3D6E]">{{ activeProductCount }}</strong><span class="text-sm text-[#637286]">Core Products</span></div>
                        <div class="border-t-2 border-[#e1edf2] pt-4"><strong class="block text-3xl text-[#0B3D6E]">1</strong><span class="text-sm text-[#637286]">Unified Demo & Licensing Experience</span></div>
                        <div class="border-t-2 border-[#e1edf2] pt-4"><strong class="block text-3xl text-[#0B3D6E]">∞</strong><span class="text-sm text-[#637286]">Room to Scale</span></div>
                    </div>
                </div>
                <div class="grid min-h-[330px] place-items-center rounded-[28px] border border-[#dce7ef] bg-gradient-to-br from-[#F7FCFE] to-[#EEF7FA] p-9 shadow-[0_22px_60px_rgba(4,18,37,.12)]">
                    <img src="/images/kernelbridge-logo.png" alt="KernelBridge Technologies LTD logo" width="520" height="520" loading="lazy" class="max-h-64 rounded-2xl object-contain" />
                </div>
            </div>
        </section>

        <section class="bg-gradient-to-br from-[#0B3D6E] to-[#086C85] py-16 text-white">
            <div class="page-shell grid items-center gap-10 lg:grid-cols-[1fr_auto]">
                <div>
                    <p class="section-label !text-[#55D8F5]">See the software before you decide</p>
                    <h2 class="mt-3 text-4xl font-bold tracking-[-.04em] sm:text-5xl">Ready to explore a KernelBridge solution?</h2>
                    <p class="mt-4 max-w-3xl text-lg text-[#D5EDF2]">Choose a product demo or contact our team to discuss the right implementation for your organisation.</p>
                </div>
                <div class="flex flex-col gap-3 sm:flex-row">
                    <Link :href="route('demo')" class="rounded-xl bg-white px-6 py-3.5 text-center font-extrabold text-[#071A33] transition hover:-translate-y-0.5">Request a Demo</Link>
                    <Link :href="route('contact')" class="rounded-xl border border-white/50 px-6 py-3.5 text-center font-extrabold transition hover:-translate-y-0.5 hover:bg-white/10">Contact Sales</Link>
                </div>
            </div>
        </section>
    </PublicLayout>
</template>
