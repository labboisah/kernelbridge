<script setup>
import PortalLayout from '@/Layouts/PortalLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    product: { type: Object, required: true },
    plan: { type: Object, required: true },
    catalog: { type: Object, default: null },
    mapping: { type: Object, default: null },
});

const grants = useForm({ grants: structuredClone(props.mapping?.grants ?? {}) });
const capabilityEnabled = (key) => grants.grants[key]?.type === 'capability' && grants.grants[key]?.enabled === true;
const limitEnabled = (key) => grants.grants[key]?.type === 'limit';

const toggleCapability = (capability) => {
    if (capabilityEnabled(capability.key)) {
        delete grants.grants[capability.key];
    } else {
        grants.grants[capability.key] = { type: 'capability', enabled: true };
    }
};

const toggleLimit = (capability) => {
    if (limitEnabled(capability.key)) {
        delete grants.grants[capability.key];
        return;
    }

    grants.grants[capability.key] = {
        type: 'limit',
        state: 'finite',
        value: 0,
        unit: capability.unit,
        scope: capability.scope,
        ...(capability.period ? { period: capability.period } : {}),
    };
};

const setLimitState = (capability, state) => {
    const grant = grants.grants[capability.key];
    if (!grant || grant.type !== 'limit') return;
    grant.state = state;
    if (state === 'unlimited') delete grant.value;
    else if (!Number.isFinite(grant.value)) grant.value = 0;
};

const submit = () => grants.put(route('admin.plans.entitlements.publish', props.plan.id));
</script>

<template>
    <Head :title="`Entitlements: ${plan.name}`" />
    <PortalLayout area="Administration">
        <template #navigation>
            <Link :href="route('admin.dashboard')" class="block rounded-lg px-3 py-2 text-slate-600">Overview</Link>
            <Link :href="route('admin.products.index')" class="block rounded-lg bg-cyan-50 px-3 py-2 font-medium text-cyan-800">Products</Link>
        </template>

        <div class="mb-6">
            <Link :href="route('admin.plans.show', plan.id)" class="text-sm font-bold text-cyan-700">← {{ plan.name }}</Link>
            <h1 class="mt-2 text-3xl font-bold">Plan → package → module → capability</h1>
            <p class="mt-2 text-slate-600">{{ product.name }} · {{ product.code }} · stable catalog keys drive grants; labels are informational.</p>
        </div>

        <div v-if="$page.props.flash.success" class="success-message mb-5">{{ $page.props.flash.success }}</div>
        <div v-if="!catalog" class="rounded-xl border border-amber-300 bg-amber-50 p-5 text-amber-900">
            Publish this product's capability catalog before assigning plan entitlements.
        </div>
        <form v-else class="space-y-5" @submit.prevent="submit">
            <div class="rounded-xl border border-slate-200 bg-white p-4 text-sm text-slate-700">
                Catalog revision {{ catalog.revision }}
                <span v-if="mapping"> · Current mapping revision {{ mapping.source_revision }} (catalog {{ mapping.catalog_revision }})</span>
                <span v-else> · No direct mapping has been published for this plan</span>
            </div>

            <section v-for="packageGroup in catalog.groups" :key="packageGroup.key" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <h2 class="text-xl font-bold">Package: {{ packageGroup.key }}</h2>
                <div v-for="module in packageGroup.modules" :key="module.key" class="mt-5 border-l-2 border-cyan-200 pl-4">
                    <h3 class="font-semibold">Module: {{ module.key }}</h3>
                    <div v-for="capability in module.capabilities" :key="capability.key" class="mt-3 rounded-xl border border-slate-100 p-4">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <div class="font-semibold">{{ capability.key }}</div>
                                <p class="mt-1 text-sm text-slate-500">{{ capability.description || capability.type }}</p>
                            </div>
                            <label v-if="capability.type === 'capability'" class="flex items-center gap-2 text-sm font-medium">
                                <input type="checkbox" :checked="capabilityEnabled(capability.key)" @change="toggleCapability(capability)" />
                                Granted
                            </label>
                            <div v-else class="flex flex-wrap items-center gap-3">
                                <label class="flex items-center gap-2 text-sm">
                                    <input type="checkbox" :checked="limitEnabled(capability.key)" @change="toggleLimit(capability)" />
                                    Set limit
                                </label>
                                <template v-if="limitEnabled(capability.key)">
                                    <select :value="grants.grants[capability.key].state" class="rounded-lg border-slate-300" @change="setLimitState(capability, $event.target.value)">
                                        <option value="finite">Finite</option>
                                        <option value="unlimited">Unlimited</option>
                                    </select>
                                    <input
                                        v-if="grants.grants[capability.key].state === 'finite'"
                                        v-model.number="grants.grants[capability.key].value"
                                        type="number" min="0" step="any"
                                        class="w-32 rounded-lg border-slate-300"
                                        :aria-label="`${capability.key} limit value`"
                                    />
                                    <span class="text-xs text-slate-500">{{ capability.unit }} · {{ capability.scope }}</span>
                                </template>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <div v-if="grants.errors.grants" class="text-sm font-medium text-red-700">{{ grants.errors.grants }}</div>
            <button class="form-button" :disabled="grants.processing">Publish direct plan mapping</button>
        </form>
    </PortalLayout>
</template>
