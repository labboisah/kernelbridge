<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import CustomerNavigation from '@/Components/Customer/CustomerNavigation.vue';

const props = defineProps({ services: Array });

const copied = ref(null);

const money = (value, currency) => new Intl.NumberFormat('en-NG', { style: 'currency', currency }).format(Number(value));
const date = (value) => value ? new Intl.DateTimeFormat('en-NG', { dateStyle: 'medium' }).format(new Date(`${value}T00:00:00`)) : 'No fixed expiry';

const copy = async (key, value) => {
    if (!value) {
        return;
    }

    await navigator.clipboard.writeText(value);
    copied.value = key;
    window.setTimeout(() => {
        if (copied.value === key) {
            copied.value = null;
        }
    }, 1800);
};
</script>

<template>
    <Head title="My services" />
    <PortalLayout area="Customer Portal">
        <template #navigation>
            <CustomerNavigation active="services" />
        </template>

        <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="text-sm font-bold uppercase tracking-wider text-cyan-700">Customer account</p>
                <h1 class="mt-2 text-3xl font-bold">My services</h1>
                <p class="mt-2 text-slate-600">Manage your KernelBridge products, plans, renewal dates and client app setup.</p>
            </div>
            <Link :href="route('portal.services.create')" class="form-button">Add service</Link>
        </div>

        <p v-if="$page.props.flash.success" class="success-message mb-5">{{ $page.props.flash.success }}</p>

        <div class="grid gap-5 lg:grid-cols-2">
            <article v-for="service in props.services" :key="service.uuid" class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
                <div class="p-6">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-sm text-slate-500">{{ service.number }}</p>
                            <h2 class="mt-1 text-xl font-bold text-[#0B3D6E]">{{ service.product }}</h2>
                            <p class="text-sm text-slate-600">{{ service.plan }} / {{ service.customer }}</p>
                        </div>
                        <span class="rounded-full bg-cyan-100 px-3 py-1 text-xs font-bold uppercase text-cyan-800">{{ service.status }}</span>
                    </div>

                    <dl class="mt-5 grid grid-cols-2 gap-4 text-sm">
                        <div>
                            <dt class="text-slate-500">Price</dt>
                            <dd class="font-semibold">{{ money(service.price, service.currency) }} / {{ service.billing_cycle }}</dd>
                        </div>
                        <div>
                            <dt class="text-slate-500">Renewal / expiry</dt>
                            <dd class="font-semibold">{{ date(service.ends_at) }}</dd>
                        </div>
                        <div>
                            <dt class="text-slate-500">Started</dt>
                            <dd class="font-semibold">{{ date(service.starts_at) }}</dd>
                        </div>
                        <div>
                            <dt class="text-slate-500">Auto-renew</dt>
                            <dd class="font-semibold">{{ service.auto_renew ? 'Enabled' : 'Disabled' }}</dd>
                        </div>
                    </dl>

                    <div class="mt-5 flex items-center gap-4">
                        <Link :href="route('portal.services.edit', service.uuid)" class="font-bold text-cyan-700">Manage service</Link>
                        <Link v-if="service.product_slug" :href="route('products.show', service.product_slug)" class="text-sm font-semibold text-slate-600">View product</Link>
                    </div>
                </div>

                <div v-if="service.client_setup" class="border-t border-slate-200 bg-slate-50 p-6">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h3 class="font-bold text-[#0B3D6E]">Client app setup</h3>
                            <p class="mt-1 text-sm text-slate-600">Use these values in the Laravel app that installs the licensing package.</p>
                        </div>
                        <span class="rounded-full bg-white px-3 py-1 text-xs font-bold text-slate-700 ring-1 ring-slate-200">{{ service.client_setup.product_code }}</span>
                    </div>

                    <div class="mt-4 space-y-4">
                        <section>
                            <div class="mb-2 flex items-center justify-between gap-3">
                                <h4 class="text-sm font-bold text-slate-700">Composer</h4>
                                <button type="button" class="text-xs font-bold text-cyan-700" @click="copy(`${service.uuid}-composer`, service.client_setup.composer_commands)">
                                    {{ copied === `${service.uuid}-composer` ? 'Copied' : 'Copy' }}
                                </button>
                            </div>
                            <pre class="max-h-44 overflow-auto rounded-lg bg-slate-950 p-4 text-xs leading-6 text-slate-100"><code>{{ service.client_setup.composer_commands }}</code></pre>
                        </section>

                        <section>
                            <div class="mb-2 flex items-center justify-between gap-3">
                                <h4 class="text-sm font-bold text-slate-700">.env</h4>
                                <button type="button" class="text-xs font-bold text-cyan-700" @click="copy(`${service.uuid}-env`, service.client_setup.env)">
                                    {{ copied === `${service.uuid}-env` ? 'Copied' : 'Copy' }}
                                </button>
                            </div>
                            <pre class="max-h-44 overflow-auto rounded-lg bg-slate-950 p-4 text-xs leading-6 text-slate-100"><code>{{ service.client_setup.env }}</code></pre>
                        </section>

                        <section>
                            <div class="mb-2 flex items-center justify-between gap-3">
                                <h4 class="text-sm font-bold text-slate-700">Protected routes</h4>
                                <button type="button" class="text-xs font-bold text-cyan-700" @click="copy(`${service.uuid}-routes`, service.client_setup.route_middleware)">
                                    {{ copied === `${service.uuid}-routes` ? 'Copied' : 'Copy' }}
                                </button>
                            </div>
                            <pre class="max-h-44 overflow-auto rounded-lg bg-slate-950 p-4 text-xs leading-6 text-slate-100"><code>{{ service.client_setup.route_middleware }}</code></pre>
                        </section>

                        <section>
                            <div class="mb-2 flex items-center justify-between gap-3">
                                <h4 class="text-sm font-bold text-slate-700">Activation key</h4>
                                <button v-if="service.client_setup.license_key" type="button" class="text-xs font-bold text-cyan-700" @click="copy(`${service.uuid}-license`, service.client_setup.license_key)">
                                    {{ copied === `${service.uuid}-license` ? 'Copied' : 'Copy' }}
                                </button>
                            </div>
                            <div class="rounded-lg bg-white p-4 text-sm font-semibold text-slate-700 ring-1 ring-slate-200">
                                <span v-if="service.client_setup.license_key">{{ service.client_setup.license_key }}</span>
                                <span v-else class="text-slate-500">License will appear here after the service is activated.</span>
                            </div>
                        </section>
                    </div>
                </div>
            </article>

            <div v-if="!props.services.length" class="rounded-2xl bg-white p-10 text-center text-slate-500 ring-1 ring-slate-200">
                No services are attached to your account yet. Add a service to get started.
            </div>
        </div>
    </PortalLayout>
</template>
