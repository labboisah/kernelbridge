<script setup>
defineProps({ title: { type: String, required: true }, code: { type: String, required: true } });
</script>

<template><div class="mt-5 overflow-hidden rounded-2xl bg-[#071426] shadow-lg"><div class="border-b border-white/10 px-5 py-3 text-xs font-bold uppercase tracking-wider text-slate-400">{{ title }}</div><pre class="overflow-x-auto p-5 text-sm leading-6 text-cyan-100" tabindex="0"><code>{{ code }}</code></pre></div></template>
