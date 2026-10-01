<script setup>
import { ref, computed, watch } from 'vue';
import axios from 'axios';
import { Link, useForm, router } from '@inertiajs/vue3';
import PackageOption from './PackageOption.vue';

const props = defineProps({ order: Object, packages: Array });
const form = useForm({ packages: [...props.order.package_uuids], use_credit: true });
const preview = ref(null);
const previewError = ref('');
const loading = ref(false);
let requestVersion = 0;
const available = computed(() => props.packages.filter(item => item.currency === props.order.currency && item.billing_cycle === props.order.billing_cycle));
const toggle = uuid => { form.packages = form.packages.includes(uuid) ? form.packages.filter(id => id !== uuid) : [...form.packages, uuid]; };
watch(() => form.packages, () => { preview.value = null; previewError.value = ''; requestVersion++; }, { deep: true });
watch(() => props.order.package_uuids, value => { form.packages = [...value]; preview.value = null; }, { deep: true });
const review = async () => {
    const version = ++requestVersion;
    loading.value = true; previewError.value = '';
    try {
        const response = await axios.post(route('portal.licenses.preview', props.order.uuid), { packages: form.packages });
        if (version === requestVersion) preview.value = response.data;
    } catch (error) {
        if (version === requestVersion) previewError.value = Object.values(error.response?.data?.errors || {}).flat().join(' ') || 'Could not load the current quote.';
    } finally { loading.value = false; }
};
const save = () => form.post(route('portal.licenses.change', props.order.uuid), { onSuccess: () => { preview.value = null; } });
const money = value => new Intl.NumberFormat('en-NG', { style: 'currency', currency: props.order.currency }).format(Number(value));
</script>
<template>
    <section class="mb-6 rounded-2xl bg-white p-6 ring-1 ring-slate-200">
        <h2 class="text-xl font-bold">{{ order.packages.join(' + ') || 'MediFlow subscription' }}</h2>
        <p class="mt-2 text-sm text-slate-600">Status: {{ order.status }} | Period ends {{ order.ends_at ? new Date(order.ends_at).toLocaleDateString() : 'after payment' }}</p>
        <div v-if="order.pending_order" class="mt-4 rounded-xl bg-amber-50 p-4">
            <p>Pending {{ order.pending_order.kind }} invoice: {{ money(order.pending_order.balance) }} remaining. Added packages stay locked until fully settled.</p>
            <Link :href="route('portal.invoices.show', order.pending_order.invoice_uuid)" class="mt-2 inline-block font-bold text-cyan-800 underline">View and settle invoice</Link>
            <button v-if="order.pending_order.can_cancel" class="ml-4 text-sm font-semibold text-red-700" @click="router.post(route('portal.licenses.cancel-order', order.pending_order.uuid))">Cancel unpaid change</button>
        </div>
        <div v-if="order.can_change" class="mt-5">
            <p class="mb-4 text-sm text-slate-600">Add or remove packages. Removals take effect immediately and unused paid time becomes account credit. Additions are prorated through the current period and start only after full payment.</p>
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <PackageOption v-for="item in available" :key="item.uuid" :item="item" :checked="form.packages.includes(item.uuid)" :disabled="!order.package_uuids.includes(item.uuid) && Number(item.price) <= 0" @toggle="toggle" />
            </div>
            <p v-if="!form.packages.length" class="mt-3 text-sm text-amber-800">Removing every package cancels access. Unused paid time remains as credit for a future subscription.</p>
            <button type="button" :disabled="loading" class="mt-5 rounded-lg border border-cyan-700 px-4 py-2 font-semibold text-cyan-800 disabled:opacity-50" @click="review">{{ loading ? 'Calculating...' : 'Review package change' }}</button>
            <p v-if="previewError" role="alert" class="mt-3 text-sm text-red-700">{{ previewError }}</p>
            <div v-if="preview" class="mt-4 rounded-xl bg-slate-50 p-4">
                <p>Addition invoice: <strong>{{ money(preview.charge) }}</strong></p>
                <p>Unused-time credit: <strong>{{ money(preview.credit) }}</strong></p>
                <p>Existing credit: <strong>{{ money(preview.available_credit) }}</strong></p>
                <p class="mt-2 text-xs text-slate-600">Amounts are recalculated when submitted. Setup fees are not credited.</p>
                <label class="mt-3 flex items-center gap-2 text-sm"><input v-model="form.use_credit" type="checkbox" /> Apply available credit to this invoice</label>
                <button type="button" :disabled="form.processing" class="mt-4 rounded-lg bg-cyan-700 px-4 py-2 font-bold text-white disabled:opacity-50" @click="save">Confirm change</button>
            </div>
        </div>
        <div v-if="order.can_renew" class="mt-4">
            <p class="text-sm">Renew these packages for the next billing period. Available credit can settle all or part of the invoice.</p>
            <label class="mt-3 flex items-center gap-2 text-sm"><input v-model="form.use_credit" type="checkbox" /> Use account credit</label>
            <button class="mt-3 rounded-lg bg-cyan-700 px-4 py-2 font-bold text-white" :disabled="form.processing" @click="form.post(route('portal.licenses.renew', order.uuid))">Prepare renewal invoice</button>
        </div>
        <p v-for="(error, key) in form.errors" :key="key" role="alert" class="mt-3 text-sm text-red-700">{{ error }}</p>
        <p class="mt-4 text-xs text-slate-600">After changes or payment, choose "Refresh subscription" in MediFlow to update its installed package configuration.</p>
    </section>
</template>
