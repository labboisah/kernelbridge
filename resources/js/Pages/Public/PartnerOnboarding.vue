<script setup>
import PublicLayout from '@/Layouts/PublicLayout.vue';
import InputError from '@/Components/InputError.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
const props = defineProps({ type: String, category: Object, application: Object });
const form = useForm({ organisation: props.application?.organisation || '', phone: props.application?.phone || '', location: props.application?.location || '', website: props.application?.website || '', details: props.application?.details || '', consent: false, action: 'save' });
const fields = [{key:'organisation', label:'Organisation (optional)', type:'text', max:255}, {key:'phone', label:'Contact phone', type:'tel', max:30}, {key:'location', label:'Country and city / region', type:'text', max:255}, {key:'website', label:'Website or portfolio URL (optional)', type:'url', max:500}];
const submit = action => { form.action = action; form.post(route('partners.save', props.type), { preserveScroll: true }); };
</script>
<template><Head :title="category.label + ' onboarding'"/><PublicLayout>
    <section class="page-hero"><div class="page-shell"><p class="section-label !text-teal-300">Partner onboarding</p><h1 class="page-title">{{ category.label }}</h1><p class="page-intro">{{ category.description }}</p></div></section>
    <section class="page-shell grid gap-10 py-14 lg:grid-cols-[.7fr_1.3fr]">
        <aside><h2 class="text-2xl font-bold">Your application</h2><ol class="mt-6 space-y-4 text-slate-600"><li>1. Account created</li><li>2. Complete your profile and interests</li><li>3. Submit for review</li></ol><p class="mt-8 text-sm">Save a draft to continue later. Your contact details will be used to respond to this application.</p><Link :href="route('partners.index')" class="mt-6 block font-bold text-[#0B3D6E]">View all partner types</Link><Link :href="route('partner.dashboard')" class="mt-4 block font-bold text-[#0B3D6E]">Partner portal</Link></aside>
        <div>
            <p v-if="$page.props.flash.success" role="status" class="success-message mb-5">{{ $page.props.flash.success }}</p>
            <div v-if="application && application.status !== 'draft'" class="form-card">
                <h2 class="text-2xl font-bold">Application {{ application.status }}</h2><p class="mt-3 text-slate-600">{{ application.status === 'submitted' ? 'Your application is awaiting review. Check this page for updates.' : 'Your application has been reviewed. Read the next steps below.' }}</p>
                <p v-if="application.review_note" class="mt-5 whitespace-pre-line rounded-xl bg-slate-50 p-4">{{ application.review_note }}</p>
                <dl class="mt-6 space-y-4"><div v-for="field in fields" :key="field.key"><dt class="font-bold">{{ field.label }}</dt><dd>{{ application[field.key] || 'Not provided' }}</dd></div><div><dt class="font-bold">Your interests</dt><dd class="whitespace-pre-line">{{ application.details }}</dd></div></dl>
            </div>
            <form v-else class="form-card" @submit.prevent="submit('submit')">
                <h2 class="text-2xl font-bold">Profile and interests</h2>
                <div class="form-grid"><label v-for="field in fields" :key="field.key" :for="field.key">{{ field.label }}<input :id="field.key" v-model="form[field.key]" :type="field.type" :maxlength="field.max"/><InputError :message="form.errors[field.key]"/></label></div>
                <label for="partner-details">{{ category.question }}<textarea id="partner-details" v-model="form.details" rows="6" maxlength="5000"/><InputError :message="form.errors.details"/></label>
                <label class="!flex items-start gap-3"><input v-model="form.consent" type="checkbox" class="!w-auto mt-1"/><span>I agree that KernelBridge may use these details to review my application and contact me about this partnership.</span></label><InputError :message="form.errors.consent"/>
                <p class="text-sm text-slate-500">Phone, location, interests and consent are required when submitting. Submitting an application does not grant staff access or confirm a commercial agreement.</p>
                <div class="flex flex-wrap gap-4"><button type="button" class="rounded-xl border border-slate-300 px-5 py-3 font-semibold" :disabled="form.processing" @click="submit('save')">Save draft</button><button class="form-button" :disabled="form.processing">{{ form.processing ? 'Saving...' : 'Submit application' }}</button></div>
            </form>
        </div>
    </section>
</PublicLayout></template>
