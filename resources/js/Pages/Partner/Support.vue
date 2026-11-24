<script setup>
import FormErrors from '@/Components/Partner/FormErrors.vue';
import Pagination from '@/Components/Partner/Pagination.vue';
import PartnerLayout from '@/Layouts/PartnerLayout.vue';
import { Link, useForm } from '@inertiajs/vue3';

defineProps({ tickets: Object, customers: Array, teams: Array });
const form = useForm({ customer_uuid: '', team_uuid: '', subject: '', priority: 'normal', message: '', attachments: [] });
const submit = () => form.post(route('partner.support.store'), { forceFormData: true });
</script>

<template>
    <PartnerLayout title="Support requests">
        <form class="form-card mb-6" @submit.prevent="submit">
            <h2 class="text-xl font-bold">Request support for a client</h2>
            <FormErrors :errors="form.errors" />
            <div class="form-grid">
                <label>Client
                    <select v-model="form.customer_uuid" required>
                        <option value="">Choose client</option>
                        <option v-for="client in customers" :key="client.uuid" :value="client.uuid">{{ client.name }}</option>
                    </select>
                </label>
                <label>Support team
                    <select v-model="form.team_uuid" required>
                        <option value="">Choose team</option>
                        <option v-for="team in teams" :key="team.uuid" :value="team.uuid">{{ team.name }}</option>
                    </select>
                </label>
                <label>Subject
                    <input v-model="form.subject" required minlength="5" maxlength="255" />
                </label>
                <label>Priority
                    <select v-model="form.priority">
                        <option v-for="priority in ['low', 'normal', 'high', 'urgent']" :key="priority">{{ priority }}</option>
                    </select>
                </label>
            </div>
            <label>Request details
                <textarea v-model="form.message" required minlength="10" maxlength="20000" rows="5" />
            </label>
            <label>Evidence and attachments
                <input type="file" multiple accept=".pdf,.txt,.jpg,.jpeg,.png,.webp,.zip" @change="form.attachments = Array.from($event.target.files)" />
                <span class="mt-1 block text-xs font-normal text-slate-500">Attach up to 5 images, PDF, text, or ZIP files. Maximum 10 MB per file.</span>
            </label>
            <button class="form-button" :disabled="form.processing || !customers.length || !teams.length">{{ form.processing ? 'Creating ticket...' : 'Create support request' }}</button>
        </form>

        <div class="space-y-3">
            <Link v-for="ticket in tickets.data" :key="ticket.uuid" :href="route('partner.support.show', ticket.uuid)" class="block rounded-xl bg-white p-5">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <strong>{{ ticket.ticket_number }} · {{ ticket.subject }}</strong>
                    <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold">{{ ticket.status.replaceAll('_', ' ') }}</span>
                </div>
                <p class="mt-2 text-sm text-slate-600">{{ ticket.customer.name }} · {{ ticket.priority }} priority · Updated {{ new Date(ticket.updated_at).toLocaleString() }}</p>
            </Link>
            <p v-if="!tickets.data.length">No support requests yet.</p>
        </div>
        <Pagination :page="tickets" />
    </PartnerLayout>
</template>
