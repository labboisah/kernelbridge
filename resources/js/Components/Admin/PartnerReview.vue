<script setup>
import { useForm } from '@inertiajs/vue3';
import InputError from '@/Components/InputError.vue';
import PartnerAnswers from '@/Components/PartnerAnswers.vue';
const props = defineProps({ application: Object, label: String, questionnaire: Array });
const form = useForm({ status: 'approved', review_note: '' });
const experience = [
    ['background_knowledge', 'Background knowledge'],
    ['digital_skills', 'Digital skills and experience'],
    ['installation_experience', 'Software and hardware installation'],
    ['networking_experience', 'Networking and internet'],
    ['business_skills', 'Business skills'],
    ['management_skills', 'Management skills'],
];
</script>
<template><article class="rounded-2xl border bg-white p-6"><h2 class="text-xl font-bold">{{ application.user.name }} - {{ label }}</h2><p class="mt-2 capitalize">{{ application.status }}</p><img v-if="application.has_photo" :src="route('partners.photo', application.id)" alt="Applicant photo" class="mt-4 h-32 w-32 object-cover"/><dl class="mt-4 grid gap-4 sm:grid-cols-2"><div v-for="field in ['organisation','phone','location','website']" :key="field"><dt class="font-semibold capitalize">{{ field }}</dt><dd class="break-words">{{ application[field] || 'Not provided' }}</dd></div><div><dt class="font-semibold">Email</dt><dd>{{ application.user.email }}</dd></div><div v-for="[key, label] in experience.filter(([key]) => application[key])" :key="key"><dt class="font-semibold">{{ label }} (previous response)</dt><dd class="whitespace-pre-line break-words">{{ application[key] }}</dd></div></dl><PartnerAnswers class="mt-5" :questionnaire="questionnaire" :answers="application.guided_answers"/><p class="my-5 whitespace-pre-line">{{ application.details }}</p><div v-if="application.bank_details" class="my-5 rounded-lg bg-slate-50 p-4"><h3 class="font-bold">Bank details</h3><p>{{ application.bank_details.bank_name }}</p><p>{{ application.bank_details.account_name }}</p><p>{{ application.bank_details.account_number }}</p></div><p v-if="application.review_note" class="whitespace-pre-line bg-slate-50 p-4">{{ application.review_note }}</p>
<form v-if="application.status === 'submitted'" class="form-card !shadow-none" @submit.prevent="form.put(route('admin.partners.update', application.id), {preserveScroll:true})"><label>Decision<select v-model="form.status"><option value="approved">Approve</option><option value="declined">Decline</option></select><InputError :message="form.errors.status"/></label><label>Decision and next steps (visible to applicant)<textarea v-model="form.review_note" required maxlength="2000" rows="3"/><InputError :message="form.errors.review_note"/></label><button class="form-button" :disabled="form.processing">Save decision</button></form></article></template>
