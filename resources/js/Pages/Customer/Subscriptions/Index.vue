<script setup>
import { Head, Link } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import CustomerNavigation from '@/Components/Customer/CustomerNavigation.vue';

defineProps({ subscriptions: { type: Array, default: () => [] } });

const money = (value, currency) => new Intl.NumberFormat('en-NG', { style: 'currency', currency }).format(Number(value));
const date = value => value ? new Intl.DateTimeFormat('en-NG', { dateStyle: 'medium' }).format(new Date(`${value}T00:00:00`)) : 'No fixed expiry';
</script>

<template>
    <Head title="Subscription" />
    <PortalLayout area="Customer Portal">
        <template #navigation><CustomerNavigation active="subscriptions" /></template>

        <div class="mb-6">
            <p class="text-sm font-bold uppercase tracking-wider text-cyan-700">Customer account</p>
            <h1 class="mt-2 text-3xl font-bold">Subscription</h1>
            <p class="mt-2 text-slate-600">View your current product subscriptions and change a plan or package when needed.</p>
        </div>

        <p v-if="$page.props.flash.success" class="success-message mb-5">{{ $page.props.flash.success }}</p>

        <div v-if="subscriptions.length" class="space-y-4">
            <article v-for="subscription in subscriptions" :key="subscription.uuid" class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p class="text-sm text-slate-500">{{ subscription.number }}</p>
                        <h2 class="mt-1 text-xl font-bold text-[#0B3D6E]">{{ subscription.product || 'Product subscription' }}</h2>
                        <p class="mt-1 text-slate-600">{{ subscription.packages?.length ? subscription.packages.join(' + ') : subscription.plan || 'Plan details unavailable' }}</p>
                    </div>
                    <span class="rounded-full bg-cyan-100 px-3 py-1 text-xs font-bold uppercase text-cyan-800">{{ subscription.status }}</span>
                </div>

                <dl class="mt-5 grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-3">
                    <div>
                        <dt class="text-slate-500">Subscription price</dt>
                        <dd class="font-semibold">{{ money(subscription.price, subscription.currency) }} / {{ subscription.billing_cycle }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Started</dt>
                        <dd class="font-semibold">{{ date(subscription.starts_at) }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Renewal / expiry</dt>
                        <dd class="font-semibold">{{ date(subscription.ends_at) }}</dd>
                    </div>
                </dl>

                <Link :href="subscription.change_url" class="mt-5 inline-flex rounded-xl bg-[#0B3D6E] px-5 py-3 font-bold text-white hover:bg-[#082f56]">
                    Change subscription
                </Link>
            </article>
        </div>

        <section v-else class="rounded-2xl bg-white p-8 text-center shadow-sm ring-1 ring-slate-200">
            <h2 class="text-lg font-bold text-slate-900">No subscriptions yet</h2>
            <p class="mt-2 text-sm text-slate-600">Your product subscriptions will appear here after purchase.</p>
            <Link :href="route('portal.services.create')" class="mt-5 inline-flex rounded-xl bg-[#0B3D6E] px-5 py-3 font-bold text-white">Browse services</Link>
        </section>
    </PortalLayout>
</template>
