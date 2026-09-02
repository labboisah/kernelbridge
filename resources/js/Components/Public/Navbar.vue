<script setup>
import { Link } from '@inertiajs/vue3';
import { ref } from 'vue';
const open = ref(false);
defineProps({ overlay: { type: Boolean, default: false } });
const links = [{ label: 'Products', route: 'products.index', active: 'products.*' }, { label: 'Services', route: 'services', active: 'services' }, { label: 'Docs', route: 'docs.licensing', active: 'docs.*' }, { label: 'About', route: 'about', active: 'about' }, { label: 'Contact', route: 'contact', active: 'contact' }];
</script>
<template>
    <header class="z-50 w-full border-b" :class="overlay ? 'absolute top-0 border-white/10 bg-[#061a35] text-white' : 'sticky top-0 border-slate-200 bg-white'">
        <nav class="mx-auto flex h-20 max-w-7xl items-center justify-between px-5 sm:px-6" aria-label="Primary navigation">
            <Link :href="route('home')" class="isolate flex items-center gap-3" aria-label="KernelBridge Technologies home" @click="open=false"><img :src="overlay ? '/images/1.png' : '/images/2.png'" alt="KernelBridge Technologies" width="447" height="431" class="h-14 w-14 object-cover" :class="overlay ? 'mix-blend-screen' : 'mix-blend-multiply'" /></Link>
            <div class="hidden items-center gap-7 lg:flex"><Link v-for="item in links" :key="item.route" :href="route(item.route)" class="text-sm font-semibold transition hover:text-[#55D8F5]" :class="route().current(item.active) ? 'text-[#55D8F5]' : (overlay ? 'text-slate-200' : 'text-slate-700')">{{ item.label }}</Link></div>
            <div class="hidden items-center gap-3 lg:flex"><Link :href="route('login')" class="px-3 py-2 text-sm font-semibold" :class="overlay ? 'text-slate-200' : 'text-[#0B3D6E]'">Login</Link><Link :href="route('demo')" class="rounded-xl bg-gradient-to-br from-[#078ED1] to-[#12BCE5] px-5 py-3 text-sm font-bold text-white shadow-lg shadow-cyan-500/20 transition hover:-translate-y-0.5">Request Demo</Link></div>
            <button class="rounded-lg p-2 lg:hidden" :class="overlay ? 'text-white' : 'text-[#0B3D6E]'" type="button" :aria-expanded="open" aria-controls="mobile-menu" aria-label="Toggle navigation" @click="open=!open"><svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path v-if="!open" stroke-linecap="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/><path v-else stroke-linecap="round" stroke-width="2" d="M6 18 18 6M6 6l12 12"/></svg></button>
        </nav>
        <div v-show="open" id="mobile-menu" class="border-t bg-white px-5 py-5 lg:hidden"><div class="space-y-1"><Link v-for="item in links" :key="item.route" :href="route(item.route)" class="block rounded-lg px-3 py-3 font-semibold text-slate-700 hover:bg-slate-50" @click="open=false">{{ item.label }}</Link><Link :href="route('login')" class="block rounded-lg px-3 py-3 font-semibold text-slate-700">Login</Link><Link :href="route('demo')" class="mt-3 block rounded-xl bg-[#0797A5] px-4 py-3 text-center font-bold text-white" @click="open=false">Request Demo</Link></div></div>
    </header>
</template>
