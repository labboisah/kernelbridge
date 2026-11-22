<script setup>
import PortalLayout from '@/Layouts/PortalLayout.vue';
import FormErrors from '@/Components/Partner/FormErrors.vue';
import Pagination from '@/Components/Partner/Pagination.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

defineProps({ roles: Array, templates: Array, messages: Object });
const mode = ref('role');
const search = ref('');
const results = ref({ users: [], customers: [] });
const searchError = ref('');
const searching = ref(false);
const searched = ref(false);
const selectedRecipient = ref(null);
const selectedTemplate = ref('');
const form = useForm({ subject: '', message: '', audience_type: 'role', audience_identifier: '', roles: [], channels: ['database', 'mail'] });
const template = useForm({ name: '', subject: '', message: '' });
const rolePreview = ref(null);
const rolePreviewError = ref('');
const rolePreviewLoading = ref(false);
const roleLabel = role => role.replaceAll('_', ' ');
watch(mode, value => {
    form.audience_type = value === 'role' ? 'role' : 'user';
    form.audience_identifier = '';
    selectedRecipient.value = null;
});
watch(search, () => {
    selectedRecipient.value = null;
    if (mode.value === 'individual') form.audience_identifier = '';
    results.value = { users: [], customers: [] };
    searched.value = false;
});
watch([mode, () => [...form.roles], () => form.channels.includes('mail')], async ([audienceMode, roles, emailEnabled], previous, onCleanup) => {
    rolePreview.value = null;
    rolePreviewError.value = '';
    rolePreviewLoading.value = false;
    if (audienceMode !== 'role' || !roles.length || !emailEnabled) return;
    const controller = new AbortController();
    onCleanup(() => controller.abort());
    rolePreviewLoading.value = true;
    try {
        const url = new URL(route('admin.notifications.role-recipients'));
        roles.forEach(role => url.searchParams.append('roles[]', role));
        const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin', signal: controller.signal });
        if (!response.ok) throw new Error('Unable to load recipients. Please change your selection or try again.');
        rolePreview.value = await response.json();
    } catch (error) {
        if (error.name !== 'AbortError') rolePreviewError.value = error.message || 'Unable to load recipients.';
    } finally {
        if (!controller.signal.aborted) rolePreviewLoading.value = false;
    }
});
const searchRecipients = async () => {
    searchError.value = '';
    searched.value = false;
    results.value = { users: [], customers: [] };
    if (search.value.trim().length < 2) {
        searchError.value = 'Enter at least two characters to search.';
        return;
    }
    searching.value = true;
    try {
        const url = new URL(route('admin.notifications.recipients'));
        url.searchParams.set('search', search.value.trim());
        const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        if (!response.ok) throw new Error('Recipient search failed. Please try again.');
        results.value = await response.json();
        searched.value = true;
    } catch (error) {
        searchError.value = error.message || 'Recipient search failed. Please try again.';
    } finally {
        searching.value = false;
    }
};
const selectRecipient = recipient => {
    selectedRecipient.value = recipient;
    form.audience_type = recipient.type;
    form.audience_identifier = recipient.id;
};
const loadTemplate = item => {
    selectedTemplate.value = item.uuid;
    template.name = item.name;
    form.subject = item.subject;
    form.message = item.message;
    template.clearErrors();
};
const saveTemplate = update => {
    template.subject = form.subject;
    template.message = form.message;
    if (update) template.put(route('admin.notification-templates.update', selectedTemplate.value), { preserveScroll: true });
    else template.post(route('admin.notification-templates.store'), { preserveScroll: true, onSuccess: () => { selectedTemplate.value = ''; template.reset(); } });
};
const removeTemplate = () => {
    if (window.confirm('Delete this notification template?')) {
        router.delete(route('admin.notification-templates.destroy', selectedTemplate.value), {
            preserveScroll: true,
            onSuccess: () => { selectedTemplate.value = ''; template.reset(); },
        });
    }
};
const submit = () => form.post(route('admin.notifications.store'), {
    preserveScroll: true,
    onSuccess: () => {
        form.reset();
        mode.value = 'role';
        selectedRecipient.value = null;
    },
});
</script>

<template>
    <Head title="Admin notifications" />
    <PortalLayout area="Administration">
        <h1 class="text-3xl font-bold text-slate-900">Notifications</h1>
        <p class="my-5 text-slate-600">Send a message to an individual or everyone in selected roles. Account holders can receive in-app notifications and email; customers without accounts can receive email only.</p>
        <p v-if="$page.props.flash.success" role="status" class="success-message mb-5">{{ $page.props.flash.success }}</p>
        <form class="form-card mb-8" @submit.prevent="submit">
            <h2 class="text-xl font-bold">Compose notification</h2>
            <FormErrors :errors="form.errors" />
            <label>Audience
                <select v-model="mode">
                    <option value="role">Group by role</option>
                    <option value="individual">Individual</option>
                </select>
            </label>
            <fieldset v-if="mode === 'role'">
                <legend class="font-semibold">Roles (select one or more)</legend>
                <div class="mt-2 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                    <label v-for="role in roles" :key="role" class="!flex items-center gap-2 capitalize">
                        <input v-model="form.roles" type="checkbox" :value="role" class="!mt-0" /> {{ roleLabel(role) }}
                    </label>
                </div>
            </fieldset>
            <div v-else class="space-y-3">
                <label>Search users or email-only customers
                    <div class="flex flex-wrap gap-2"><input v-model="search" maxlength="100" :disabled="searching" placeholder="Name or email" @keydown.enter.prevent="searchRecipients" /><button type="button" class="rounded-lg border border-slate-300 px-4 py-2 font-semibold" :disabled="searching" @click="searchRecipients">{{ searching ? 'Searching...' : 'Search' }}</button></div>
                </label>
                <p v-if="searchError" role="alert" class="text-sm text-red-700">{{ searchError }}</p>
                <p v-if="selectedRecipient" class="text-sm font-semibold">Selected: {{ selectedRecipient.name }} ({{ selectedRecipient.email }})</p>
                <div v-if="results.users.length || results.customers.length" class="max-h-60 overflow-y-auto rounded-lg border border-slate-200">
                    <button v-for="recipient in [...results.users, ...results.customers]" :key="`${recipient.type}-${recipient.id}`" type="button" class="block w-full border-b border-slate-100 p-3 text-left text-sm hover:bg-slate-50" @click="selectRecipient(recipient)">
                        {{ recipient.name }} - {{ recipient.email }} <span class="text-slate-500">({{ recipient.type === 'customer' ? 'email only' : `account: ${recipient.status}` }})</span>
                    </button>
                </div>
                <p v-else-if="searched" class="text-sm text-slate-500">No matching recipients found.</p>
            </div>
            <fieldset>
                <legend class="font-semibold">Delivery channels</legend>
                <div class="mt-2 flex flex-wrap gap-5">
                    <label class="!flex items-center gap-2"><input v-model="form.channels" type="checkbox" value="database" class="!mt-0" /> In-app</label>
                    <label class="!flex items-center gap-2"><input v-model="form.channels" type="checkbox" value="mail" class="!mt-0" /> Email</label>
                </div>
            </fieldset>
            <p class="text-sm text-slate-500">Customer groups include linked account holders and customers without accounts. Email-only customers are skipped if email is not selected or their address is missing or invalid. Groups include suspended accounts.</p>
            <section v-if="mode === 'role' && form.roles.length && form.channels.includes('mail')" class="rounded-lg border border-slate-200 bg-slate-50 p-4">
                <h3 class="font-bold">Email recipients</h3>
                <p class="mt-1 text-sm text-slate-500">Each email address appears once, even when a person belongs to multiple selected roles. Membership is checked again when you send.</p>
                <p v-if="rolePreviewLoading" role="status" class="mt-3 text-sm text-slate-600">Loading recipients...</p>
                <p v-else-if="rolePreviewError" role="alert" class="mt-3 text-sm text-red-700">{{ rolePreviewError }}</p>
                <template v-else-if="rolePreview">
                    <p class="mt-3 text-sm font-semibold">{{ rolePreview.recipients.length }} email recipient(s)</p>
                    <ul v-if="rolePreview.recipients.length" class="mt-2 max-h-60 space-y-1 overflow-y-auto text-sm text-slate-700">
                        <li v-for="recipient in rolePreview.recipients" :key="recipient.email">{{ recipient.name }} — {{ recipient.email }}<span v-if="recipient.type === 'customer'" class="text-slate-500"> (email only)</span></li>
                    </ul>
                    <p v-else class="mt-2 text-sm text-slate-600">No email recipients found for these roles.</p>
                    <p v-if="rolePreview.unreachable_customers" class="mt-2 text-sm text-slate-600">{{ rolePreview.unreachable_customers }} customer(s) without accounts have no valid email and will be skipped.</p>
                    <div v-if="rolePreview.invalid_users.length" role="alert" class="mt-2 text-sm text-red-700">
                        <p>These users have invalid email addresses. Correct them or send in-app only:</p>
                        <ul class="list-disc pl-5"><li v-for="user in rolePreview.invalid_users" :key="user.name + user.email">{{ user.name }} — {{ user.email || 'no email' }}</li></ul>
                    </div>
                </template>
            </section>
            <label>Subject<input v-model="form.subject" required maxlength="180" /></label>
            <label>Message<textarea v-model="form.message" required maxlength="5000" rows="6" /></label>
            <button class="form-button" :disabled="form.processing || !form.channels.length || (mode === 'individual' ? !form.audience_identifier : !form.roles.length || (form.channels.includes('mail') && (rolePreviewLoading || !rolePreview || rolePreview.invalid_users.length)))">{{ form.processing ? 'Submitting...' : 'Send notification' }}</button>
        </form>

        <section class="form-card mb-8">
            <h2 class="text-xl font-bold">Reusable templates</h2>
            <p class="text-sm text-slate-600">Load a template into the composer, or save the current subject and message as a template.</p>
            <FormErrors :errors="template.errors" />
            <label>Template name<input v-model="template.name" maxlength="120" /></label>
            <div class="flex flex-wrap gap-3">
                <button type="button" class="form-button" :disabled="template.processing || !template.name || !form.subject || !form.message" @click="saveTemplate(false)">Save as new</button>
                <button v-if="selectedTemplate" type="button" class="rounded-lg border border-slate-300 px-4 py-2 font-semibold" :disabled="template.processing || !template.name || !form.subject || !form.message" @click="saveTemplate(true)">Update selected</button>
                <button v-if="selectedTemplate" type="button" class="rounded-lg border border-red-300 px-4 py-2 font-semibold text-red-700" @click="removeTemplate">Delete selected</button>
            </div>
            <div v-if="templates.length" class="space-y-2">
                <button v-for="item in templates" :key="item.uuid" type="button" class="block w-full rounded-lg border px-3 py-2 text-left text-sm" :class="selectedTemplate === item.uuid ? 'border-cyan-600 bg-cyan-50' : 'border-slate-200'" @click="loadTemplate(item)">{{ item.name }} - {{ item.subject }}</button>
            </div>
            <p v-else class="text-sm text-slate-500">No templates saved yet.</p>
        </section>

        <section>
            <h2 class="mb-2 text-xl font-bold">Submission history</h2>
            <p class="mb-4 text-sm text-slate-600">Submitted notifications are queued, not a confirmation of email delivery. Run a worker for the notifications queue and monitor failed jobs.</p>
            <div class="space-y-3">
                <article v-for="message in messages.data" :key="message.uuid" class="rounded-xl bg-white p-5">
                    <h3 class="font-bold">{{ message.subject }}</h3>
                    <p class="mt-1 whitespace-pre-wrap text-sm text-slate-600">{{ message.message }}</p>
                    <p class="mt-2 text-sm text-slate-500">{{ message.audience_label }} · {{ message.recipient_count }} recipients · {{ message.email_only_count }} email-only · {{ message.unreachable_count }} unreachable · {{ message.channels.join(', ') }} · {{ message.creator?.name || 'Former administrator' }} · {{ new Date(message.created_at).toLocaleString() }}</p>
                </article>
                <p v-if="!messages.data.length" class="rounded-xl bg-white p-5 text-slate-600">No notifications submitted yet.</p>
            </div>
            <Pagination :page="messages" />
        </section>
    </PortalLayout>
</template>
