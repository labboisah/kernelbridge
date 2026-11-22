<script setup>
import PartnerLayout from '@/Layouts/PartnerLayout.vue';
import Pagination from '@/Components/Partner/Pagination.vue';
import { Link } from '@inertiajs/vue3';

defineProps({ leads: Object, statuses: Array });
const label = status => status.replaceAll('_', ' ');
</script>

<template>
    <PartnerLayout title="CRM pipeline">
        <p class="mb-6 text-slate-600">Track interested, consenting prospects through each stage of the sales process. Register new prospects in the PCR first.</p>
        <Link :href="route('partner.pcr.index')" class="mb-6 inline-block rounded-lg bg-[#0B3D6E] px-4 py-2.5 font-semibold text-white">Open PCR register</Link>

        <section aria-label="Pipeline stages">
            <h2 class="mb-2 text-xl font-bold">Your pipeline</h2>
            <p class="mb-4 text-sm text-slate-600">Showing opportunities on page {{ leads.current_page }} of {{ leads.last_page }}. Open an opportunity to view its stage history and update it.</p>
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <section v-for="status in statuses" :key="status" class="rounded-xl bg-white p-4">
                    <h3 class="font-bold capitalize">{{ label(status) }}</h3>
                    <div class="mt-3 space-y-3">
                        <Link v-for="lead in leads.data.filter(item => item.status === status)" :key="lead.uuid" :href="route('partner.crm.show', lead.uuid)" class="block rounded-lg border p-3 hover:bg-slate-50">
                            <span class="block font-bold">{{ lead.organization || lead.name }}</span>
                            <span class="block text-sm text-slate-600">{{ lead.name }} · {{ lead.lead_number }}</span>
                        </Link>
                        <p v-if="!leads.data.some(item => item.status === status)" class="text-sm text-slate-500">None on this page.</p>
                    </div>
                </section>
            </div>
            <Pagination :page="leads" />
        </section>
    </PartnerLayout>
</template>
