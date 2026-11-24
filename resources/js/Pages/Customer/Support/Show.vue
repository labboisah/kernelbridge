<script setup>
import CustomerNavigation from '@/Components/Customer/CustomerNavigation.vue';
import InputError from '@/Components/InputError.vue';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({ ticket: Object, products: Array, teams: Array });
const firstMessage = props.ticket.messages.find(message => message.type === 'reply');
const editing = ref(false);
const replyForm = useForm({ body: '', attachments: [] });
const editForm = useForm({
    product_uuid: props.ticket.product?.uuid || '',
    team_uuid: props.ticket.team?.uuid || '',
    subject: props.ticket.subject,
    priority: props.ticket.priority,
    message: firstMessage?.body || '',
});
const resolveForm = useForm({});
const editable = !['resolved', 'closed'].includes(props.ticket.status);
const formatDate = value => value ? new Date(value).toLocaleString() : 'Not yet';
const reply = () => replyForm.post(route('support.messages.store', props.ticket.uuid), {
    forceFormData: true,
    preserveScroll: true,
    onSuccess: () => replyForm.reset(),
});
const updateTicket = () => editForm.put(route('support.update', props.ticket.uuid), {
    preserveScroll: true,
    onSuccess: () => { editing.value = false; },
});
const resolveTicket = () => resolveForm.post(route('support.resolve', props.ticket.uuid), { preserveScroll: true });
</script>

<template>
    <Head :title="ticket.ticket_number" />
    <PortalLayout area="Customer Portal">
        <template #navigation><CustomerNavigation active="support" /></template>
        <div v-if="$page.props.flash.success" class="success-message mb-4">{{ $page.props.flash.success }}</div>
        <div class="mb-6 rounded-2xl bg-white p-6 shadow-sm">
            <p class="text-sm font-bold text-cyan-700">{{ ticket.ticket_number }}</p>
            <h1 class="mt-1 text-3xl font-bold">{{ ticket.subject }}</h1>
            <div class="mt-4 grid gap-3 text-sm text-slate-600 sm:grid-cols-2 lg:grid-cols-3">
                <p><strong>Status:</strong> {{ ticket.status.replaceAll('_', ' ') }}</p>
                <p><strong>Priority:</strong> {{ ticket.priority }}</p>
                <p><strong>Support team:</strong> {{ ticket.team?.name || ticket.category }}</p>
                <p><strong>Product:</strong> {{ ticket.product?.name || 'General support' }}</p>
                <p><strong>Opened:</strong> {{ formatDate(ticket.opened_at) }}</p>
                <p><strong>Last updated:</strong> {{ formatDate(ticket.updated_at) }}</p>
                <p><strong>First response:</strong> {{ formatDate(ticket.first_responded_at) }}</p>
                <p><strong>Response due:</strong> {{ formatDate(ticket.first_response_due_at) }}</p>
                <p><strong>Resolution due:</strong> {{ formatDate(ticket.resolution_due_at) }}</p>
                <p v-if="ticket.resolved_at"><strong>Resolved:</strong> {{ formatDate(ticket.resolved_at) }}</p>
            </div>
            <div v-if="editable" class="mt-5 flex flex-wrap gap-3">
                <button type="button" class="rounded-lg border border-slate-300 px-4 py-2 font-semibold" @click="editing = !editing">{{ editing ? 'Cancel edit' : 'Edit ticket' }}</button>
                <button type="button" class="rounded-lg bg-emerald-700 px-4 py-2 font-semibold text-white disabled:opacity-50" :disabled="resolveForm.processing" @click="resolveTicket">{{ resolveForm.processing ? 'Resolving...' : 'Mark as resolved' }}</button>
            </div>
            <p v-if="ticket.sla_breached_at" class="mt-3 text-sm font-semibold text-red-700">This ticket has exceeded its service target.</p>
        </div>

        <form v-if="editing && editable" class="form-card mb-6" @submit.prevent="updateTicket">
            <h2 class="text-xl font-bold">Edit ticket details</h2>
            <div class="form-grid">
                <label>Product
                    <select v-model="editForm.product_uuid">
                        <option value="">General support</option>
                        <option v-for="product in products" :key="product.uuid" :value="product.uuid">{{ product.name }}</option>
                    </select>
                    <InputError :message="editForm.errors.product_uuid" />
                </label>
                <label>Support team
                    <select v-model="editForm.team_uuid" required>
                        <option v-for="team in teams" :key="team.uuid" :value="team.uuid">{{ team.name }}</option>
                    </select>
                    <InputError :message="editForm.errors.team_uuid" />
                </label>
                <label>Priority
                    <select v-model="editForm.priority">
                        <option v-for="value in ['low', 'normal', 'high', 'urgent']" :key="value">{{ value }}</option>
                    </select>
                    <InputError :message="editForm.errors.priority" />
                </label>
                <label>Subject
                    <input v-model="editForm.subject" required minlength="5" maxlength="255" />
                    <InputError :message="editForm.errors.subject" />
                </label>
            </div>
            <label>Issue details
                <textarea v-model="editForm.message" rows="6" required minlength="10" maxlength="20000" />
                <InputError :message="editForm.errors.message" />
            </label>
            <button class="form-button" :disabled="editForm.processing">{{ editForm.processing ? 'Saving...' : 'Save ticket details' }}</button>
        </form>

        <div class="space-y-4">
            <article v-for="message in ticket.messages" :key="message.uuid" class="rounded-2xl bg-white p-6 shadow-sm">
                <div class="flex justify-between gap-3">
                    <strong>{{ message.sender.name }}</strong>
                    <time class="text-sm text-slate-500">{{ formatDate(message.created_at) }}</time>
                </div>
                <p class="mt-3 whitespace-pre-line">{{ message.body }}</p>
                <div v-if="message.attachments.length" class="mt-3 space-y-1">
                    <a v-for="file in message.attachments" :key="file.uuid" :href="route('support.attachments.show', file.uuid)" class="block text-sm font-bold text-cyan-700">{{ file.original_name }}</a>
                </div>
            </article>
        </div>

        <form v-if="ticket.status !== 'closed'" class="form-card mt-6" @submit.prevent="reply">
            <h2 class="text-xl font-bold">Reply to ticket</h2>
            <p v-if="ticket.status === 'resolved'" class="text-sm text-slate-600">Sending a reply will reopen this ticket.</p>
            <label>Reply
                <textarea v-model="replyForm.body" rows="5" required minlength="2" maxlength="20000" />
                <InputError :message="replyForm.errors.body" />
            </label>
            <label>Evidence and attachments
                <input type="file" multiple accept=".pdf,.txt,.jpg,.jpeg,.png,.webp,.zip" @change="replyForm.attachments = Array.from($event.target.files)" />
                <span class="mt-1 block text-xs font-normal text-slate-500">Attach up to 5 images, PDF, text, or ZIP files. Maximum 10 MB per file.</span>
                <InputError :message="replyForm.errors.attachments" />
                <InputError v-for="(_, index) in replyForm.attachments" :key="index" :message="replyForm.errors[`attachments.${index}`]" />
            </label>
            <button class="form-button" :disabled="replyForm.processing">{{ replyForm.processing ? 'Sending...' : 'Send reply' }}</button>
        </form>
        <p v-else class="mt-6 rounded-xl bg-slate-200 p-4">This ticket is closed. Contact support if it needs to be reopened.</p>
        <Link :href="route('support.index')" class="mt-6 inline-block font-semibold text-cyan-700">Back to support tickets</Link>
    </PortalLayout>
</template>
