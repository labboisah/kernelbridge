<script setup>
import { Head } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import CustomerNavigation from '@/Components/Customer/CustomerNavigation.vue';

defineProps({ organizations: { type: Array, default: () => [] } });

const location = organization => [organization.city, organization.state, organization.country].filter(Boolean).join(', ');
</script>

<template>
    <Head title="Organization" />
    <PortalLayout area="Customer Portal">
        <template #navigation><CustomerNavigation active="organization" /></template>

        <div class="mb-6">
            <p class="text-sm font-bold uppercase tracking-wider text-cyan-700">Customer account</p>
            <h1 class="mt-2 text-3xl font-bold">Organization</h1>
            <p class="mt-2 text-slate-600">Organization details associated with your KernelBridge customer account.</p>
        </div>

        <div v-if="organizations.length" class="space-y-5">
            <article v-for="organization in organizations" :key="organization.uuid" class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p class="text-sm text-slate-500">{{ organization.customer_number || 'Customer account' }}</p>
                        <h2 class="mt-1 text-xl font-bold text-[#0B3D6E]">{{ organization.name }}</h2>
                        <p class="mt-1 text-sm capitalize text-slate-600">{{ organization.type }}</p>
                    </div>
                    <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold uppercase text-slate-700">{{ organization.status }}</span>
                </div>

                <dl class="mt-6 grid gap-4 text-sm sm:grid-cols-2">
                    <div v-if="organization.email">
                        <dt class="text-slate-500">Email</dt>
                        <dd class="font-semibold">{{ organization.email }}</dd>
                    </div>
                    <div v-if="organization.phone">
                        <dt class="text-slate-500">Phone</dt>
                        <dd class="font-semibold">{{ organization.phone }}</dd>
                    </div>
                    <div v-if="organization.website">
                        <dt class="text-slate-500">Website</dt>
                        <dd class="break-all font-semibold">{{ organization.website }}</dd>
                    </div>
                    <div v-if="organization.tax_id">
                        <dt class="text-slate-500">Tax ID</dt>
                        <dd class="font-semibold">{{ organization.tax_id }}</dd>
                    </div>
                    <div v-if="organization.billing_address || location(organization)" class="sm:col-span-2">
                        <dt class="text-slate-500">Billing address</dt>
                        <dd class="font-semibold">{{ [organization.billing_address, location(organization)].filter(Boolean).join(', ') }}</dd>
                    </div>
                </dl>

                <section v-if="organization.contacts.length" class="mt-6 border-t border-slate-200 pt-5">
                    <h3 class="font-bold text-slate-900">Organization contacts</h3>
                    <ul class="mt-3 grid gap-3 sm:grid-cols-2">
                        <li v-for="contact in organization.contacts" :key="contact.email || contact.name" class="rounded-xl bg-slate-50 p-4 text-sm">
                            <p class="font-semibold">{{ contact.name }}<span v-if="contact.is_primary" class="ml-2 text-xs font-medium text-cyan-800">Primary contact</span></p>
                            <p v-if="contact.job_title" class="mt-1 text-slate-600">{{ contact.job_title }}</p>
                            <p v-if="contact.email" class="mt-1 text-slate-600">{{ contact.email }}</p>
                            <p v-if="contact.phone" class="mt-1 text-slate-600">{{ contact.phone }}</p>
                        </li>
                    </ul>
                </section>
            </article>
        </div>

        <section v-else class="rounded-2xl bg-white p-8 text-center shadow-sm ring-1 ring-slate-200">
            <h2 class="text-lg font-bold text-slate-900">No organization linked</h2>
            <p class="mt-2 text-sm text-slate-600">Contact support to connect your customer account to an organization.</p>
        </section>
    </PortalLayout>
</template>
