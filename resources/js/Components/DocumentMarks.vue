<script setup>
defineProps({ assets: { type: Object, required: true } });
</script>

<template>
    <div v-if="assets.signature || assets.stamp" class="mt-8 flex flex-wrap items-end gap-8">
        <div v-if="assets.signature">
            <img :src="assets.signature" alt="Company signature" class="max-h-20 max-w-40 object-contain" />
            <p class="mt-2 text-sm text-slate-500">Authorized signature</p>
        </div>
        <div v-if="assets.stamp">
            <img :src="assets.stamp" alt="Company stamp" class="max-h-24 max-w-28 object-contain" />
            <p class="mt-2 text-sm text-slate-500">Company stamp</p>
        </div>
    </div>
</template>
