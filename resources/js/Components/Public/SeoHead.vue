<script setup>
import { Head } from '@inertiajs/vue3';
defineProps({ title: { type: String, required: true }, description: { type: String, required: true }, canonical: { type: String, default: '' } });
</script>
<template><Head><title>{{ title }}</title><meta head-key="description" name="description" :content="description" /><meta head-key="og:title" property="og:title" :content="title" /><meta head-key="og:description" property="og:description" :content="description" /><meta head-key="og:type" property="og:type" content="website" /><meta head-key="og:url" property="og:url" :content="canonical || $page.url" /><meta head-key="twitter:card" name="twitter:card" content="summary_large_image" /><link v-if="canonical" head-key="canonical" rel="canonical" :href="canonical" /></Head></template>
