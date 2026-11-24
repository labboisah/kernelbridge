<script setup>
import CustomerNavigation from '@/Components/Customer/CustomerNavigation.vue';
import InputError from '@/Components/InputError.vue';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({ products: Array, teams: Array });
const form = useForm({ product_uuid: '', team_uuid: props.teams[0]?.uuid || '', subject: '', priority: 'normal', message: '', attachments: [] });
const submit = () => form.post(route('support.store'), { forceFormData: true });
</script>

<template>
    <Head title="Open support ticket" />
    <PortalLayout area="Customer Portal">
        <template #navigation><CustomerNavigation active="support" /></template>
        <h1 class="mb-6 text-3xl font-bold">Open support ticket</h1>
        <form class="form-card" @submit.prevent="submit">
            <div class="form-grid">
                <label>Product
                    <select v-model="form.product_uuid">
                        <option value="">General support</option>
                        <option v-for="product in products" :key="product.uuid" :value="product.uuid">{{ product.name }}</option>
                    </select>
                    <InputError :message="form.errors.product_uuid" />
                </label>
                <label>Support team
                    <select v-model="form.team_uuid" required>
                        <option v-for="team in teams" :key="team.uuid" :value="team.uuid">{{ team.name }}</option>
                    </select>
                    <span class="mt-1 block text-xs text-slate-500">{{ teams.find(team => team.uuid === form.team_uuid)?.description }}</span>
                    <InputError :message="form.errors.team_uuid" />
                </label>
                <label>Priority
                    <select v-model="form.priority">
                        <option v-for="value in ['low', 'normal', 'high', 'urgent']" :key="value">{{ value }}</option>
                    </select>
                    <InputError :message="form.errors.priority" />
                </label>
                <label>Subject
                    <input v-model="form.subject" required minlength="5" maxlength="255" />
                    <InputError :message="form.errors.subject" />
                </label>
            </div>
            <label>How can we help?
                <textarea v-model="form.message" rows="8" required minlength="10" maxlength="20000" />
                <InputError :message="form.errors.message" />
            </label>
            <label>Evidence and attachments
                <input type="file" multiple accept=".pdf,.txt,.jpg,.jpeg,.png,.webp,.zip" @change="form.attachments = Array.from($event.target.files)" />
                <span class="mt-1 block text-xs font-normal text-slate-500">Attach up to 5 images, PDF, text, or ZIP files. Maximum 10 MB per file.</span>
                <InputError :message="form.errors.attachments" />
                <InputError v-for="(_, index) in form.attachments" :key="index" :message="form.errors[`attachments.${index}`]" />
            </label>
            <div class="flex gap-3">
                <button class="form-button" :disabled="form.processing">{{ form.processing ? 'Creating ticket...' : 'Create ticket' }}</button>
                <Link :href="route('support.index')" class="rounded-xl border px-6 py-3">Cancel</Link>
            </div>
        </form>
    </PortalLayout>
</template>
