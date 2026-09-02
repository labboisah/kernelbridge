<script setup>defineProps({ title: String, description: String, number: String });</script>
<template><article class="rounded-2xl border border-slate-200 bg-white p-7"><span class="text-sm font-bold text-[#0797A5]">{{ number }}</span><h3 class="mt-4 text-xl font-bold text-[#0B3D6E]">{{ title }}</h3><p class="mt-3 leading-7 text-slate-600">{{ description }}</p></article></template>
