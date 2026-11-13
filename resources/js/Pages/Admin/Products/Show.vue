<script setup>
import PortalLayout from '@/Layouts/PortalLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';

const props = defineProps({ product: { type: Object, required: true } });
const remove = () => {
    if (window.confirm(`Delete ${props.product.name}? This cannot be undone.`)) {
        router.delete(route('admin.products.destroy', props.product.slug));
    }
};
const buildRelease = () => router.post(route('admin.products.release.build', props.product.slug));
const moneySize = (size) => size ? `${(size / 1024 / 1024).toFixed(1)} MB` : 'Not built';
</script>

<template>
    <Head :title="product.name" />
    <PortalLayout area="Administration">
        <template #navigation>
            <Link :href="route('admin.dashboard')" class="block rounded-lg px-3 py-2 text-slate-600">Overview</Link>
            <Link :href="route('admin.products.index')" class="block rounded-lg bg-cyan-50 px-3 py-2 font-medium text-cyan-800">Products</Link>
        </template>
        <div v-if="$page.props.flash.success" class="success-message mb-5">{{ $page.props.flash.success }}</div>
        <div v-if="$page.props.flash.error" role="alert" class="mb-5 rounded-xl border border-red-200 bg-red-50 p-4 text-red-800">{{ $page.props.flash.error }}</div>
        <div class="space-y-7 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <div class="flex flex-col gap-5 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h1 class="text-3xl font-bold text-slate-900">{{ product.name }}</h1>
                    <p class="mt-2 text-slate-600">{{ product.short_description }}</p>
                    <p class="mt-1 text-sm text-slate-500">{{ product.code }} · {{ product.category }} · {{ product.status }}</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Link :href="route('admin.products.plans.index', product.slug)" class="rounded-xl border border-cyan-200 px-5 py-3 font-bold text-cyan-800">Plans</Link>
                    <Link :href="route('admin.products.edit', product.slug)" class="form-button">Edit</Link>
                    <button class="rounded-xl border border-red-200 px-5 py-3 font-bold text-red-700" @click="remove">Delete</button>
                </div>
            </div>

            <p class="whitespace-pre-line text-slate-600">{{ product.description }}</p>

            <section class="rounded-xl border border-slate-200 bg-slate-50 p-5">
                <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
                    <div>
                        <h2 class="text-xl font-bold text-slate-900">Installation release</h2>
                        <p class="mt-1 text-sm text-slate-600">Repository: {{ product.repository?.repository_url || 'Not configured' }}</p>
                        <p class="text-sm text-slate-600">Visibility: {{ product.repository?.repository_visibility || 'Not set' }}</p>
                        <p class="text-sm text-slate-600">Selected stable release: {{ product.repository?.stable_release_version || 'Not set' }} <span v-if="product.repository?.stable_release_tag">({{ product.repository.stable_release_tag }})</span></p>
                    </div>
                    <button
                        type="button"
                        class="rounded-xl bg-cyan-700 px-5 py-3 font-bold text-white disabled:cursor-not-allowed disabled:opacity-50"
                        :disabled="!product.repository?.repository_url || product.release?.status === 'building'"
                        @click="buildRelease"
                    >{{ product.release?.status === 'building' ? 'Building archive…' : 'Build selected release' }}</button>
                </div>
                <div v-if="product.release" class="mt-4 rounded-lg border border-slate-200 bg-white p-4 text-sm">
                    <p><strong>Status:</strong> {{ product.release.status }}</p>
                    <p><strong>Version/tag:</strong> {{ product.release.version }} / {{ product.release.tag }}</p>
                    <p><strong>Archive:</strong> {{ moneySize(product.release.archive_size) }}</p>
                    <p v-if="product.release.sha256"><strong>SHA-256:</strong> <code class="break-all">{{ product.release.sha256 }}</code></p>
                    <p v-if="product.release.failure_code" role="alert" class="mt-1 text-red-700"><strong>Build issue:</strong> {{ product.release.failure_code }}</p>
                </div>
                <p class="mt-3 text-xs text-slate-500">The download stays private and becomes available to a customer only after full payment is verified. Install the configured read-only GitHub App on private repositories before building.</p>
                <p v-if="product.repository?.installation_guide_markdown" class="mt-3 text-xs text-slate-600">Installation guide is shown to customers in the portal after their invoice is fully paid.</p>
            </section>

            <div class="grid gap-8 md:grid-cols-3">
                <section><h2 class="font-bold">Features ({{ product.feature_records?.length || 0 }})</h2><ul class="mt-3 space-y-2 text-sm text-slate-600"><li v-for="item in product.feature_records" :key="item.uuid">{{ item.name }}</li></ul></section>
                <section><h2 class="font-bold">Media ({{ product.media?.length || 0 }})</h2><ul class="mt-3 space-y-2 text-sm text-slate-600"><li v-for="item in product.media" :key="item.uuid">{{ item.title || item.type }}</li></ul></section>
                <section><h2 class="font-bold">Links ({{ product.links?.length || 0 }})</h2><ul class="mt-3 space-y-2 text-sm"><li v-for="item in product.links" :key="item.uuid"><a :href="item.url" target="_blank" rel="noopener noreferrer" class="text-cyan-700">{{ item.label }}</a></li></ul></section>
            </div>
        </div>
    </PortalLayout>
</template>
