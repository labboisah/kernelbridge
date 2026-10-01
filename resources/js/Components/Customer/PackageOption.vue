<script setup>
defineProps({ item: Object, checked: Boolean, disabled: Boolean });
defineEmits(['toggle']);
</script>
<template>
    <article class="rounded-xl border p-4" :class="checked ? 'border-cyan-600 bg-cyan-50' : 'border-slate-200 bg-white'">
        <label class="flex cursor-pointer items-start gap-3">
            <input type="checkbox" :checked="checked" :disabled="disabled" class="mt-1 rounded text-cyan-700" @change="$emit('toggle', item.uuid)" />
            <span><strong>{{ item.name }}</strong><span class="mt-1 block text-sm">{{ Number(item.price) > 0 ? new Intl.NumberFormat('en-NG', { style: 'currency', currency: item.currency }).format(Number(item.price)) + ' / ' + item.billing_cycle : 'Contact sales' }}</span></span>
        </label>
        <p class="mt-3 text-sm text-slate-600">{{ item.description }}</p>
        <p v-if="Number(item.setup_fee) > 0" class="mt-2 text-xs text-slate-600">One-time setup: {{ item.currency }} {{ item.setup_fee }}</p>
        <details class="mt-3">
            <summary class="cursor-pointer text-sm font-semibold text-cyan-800">Included features ({{ (item.features || item.modules).length }})</summary>
            <ul class="mt-3 space-y-3">
                <li v-for="feature in item.features || item.modules" :key="feature.key" class="text-sm">
                    <strong>{{ feature.name }}</strong><span v-if="feature.required" class="ml-2 text-xs text-cyan-800">Required</span>
                    <p class="mt-1 text-xs text-slate-600">{{ feature.description }}</p>
                </li>
            </ul>
        </details>
    </article>
</template>
