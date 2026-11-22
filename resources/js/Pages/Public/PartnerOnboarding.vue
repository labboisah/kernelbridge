<script setup>
import PublicLayout from '@/Layouts/PublicLayout.vue';
import InputError from '@/Components/InputError.vue';
import PartnerAnswers from '@/Components/PartnerAnswers.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({ type: String, category: Object, application: Object, questionnaire: Array, readOnly: Boolean });
const contact = [
    {key:'organisation', label:'Organisation (optional)', type:'text', max:255},
    {key:'phone', label:'Contact phone', type:'tel', max:30},
    {key:'location', label:'Country and city / region', type:'text', max:255},
    {key:'website', label:'Website or portfolio URL (optional)', type:'url', max:500},
];
const experience = [
    {key:'background_knowledge', label:'Background knowledge'},
    {key:'digital_skills', label:'Digital skills and experience'},
    {key:'installation_experience', label:'Software and hardware installation experience'},
    {key:'networking_experience', label:'Networking and internet experience'},
    {key:'business_skills', label:'Business skills'},
    {key:'management_skills', label:'Management skills'},
];
const step = ref(0);
const finalStep = props.questionnaire.length + 1;
const questions = props.questionnaire.flatMap(section => section.questions);
const form = useForm({
    organisation: props.application?.organisation || '', phone: props.application?.phone || '',
    location: props.application?.location || '', website: props.application?.website || '',
    details: props.application?.details || '',
    guided_answers: Object.fromEntries(questions.map(question => [
        question.key, props.application?.guided_answers?.[question.key] ?? (question.type === 'multiple' ? [] : null),
    ])),
    photo: null, consent: false, action: 'save',
});
const submit = action => {
    form.action = action;
    form.transform(data => ({ ...data, guided_answers: JSON.stringify(data.guided_answers) }));
    form.post(route('partners.save', props.type), {
        forceFormData: true,
        preserveScroll: true,
        onError: errors => {
            const section = props.questionnaire.findIndex(group => group.questions.some(question =>
                Object.keys(errors).some(key => key === `answers.${question.key}` || key.startsWith(`answers.${question.key}.`))));
            step.value = contact.some(field => errors[field.key]) ? 0 : section >= 0 ? section + 1 : finalStep;
        },
        onSuccess: () => { form.photo = null; },
    });
};
</script>

<template>
<Head :title="category.label + ' onboarding'"/><PublicLayout>
    <section class="page-hero"><div class="page-shell"><p class="section-label !text-teal-300">Partner onboarding</p><h1 class="page-title">{{ category.label }}</h1><p class="page-intro">{{ category.description }}</p></div></section>
    <section class="page-shell grid gap-10 py-14 lg:grid-cols-[.7fr_1.3fr]">
        <aside><h2 class="text-2xl font-bold">Your application</h2><ol class="mt-6 space-y-4 text-slate-600"><li>1. Contact details</li><li v-for="(section, index) in questionnaire" :key="section.title">{{ index + 2 }}. {{ section.title }}</li><li>{{ finalStep + 1 }}. Interests, photo and consent</li></ol><p class="mt-8 text-sm">{{ readOnly ? 'Your saved application is view-only. Contact our team if you need to make changes.' : 'Save a draft to continue later. Your details will be used to review your application.' }}</p><Link v-if="!readOnly" :href="route('partners.index')" class="mt-6 block font-bold text-[#0B3D6E]">View all partner types</Link><Link :href="route('partner.dashboard')" class="mt-4 block font-bold text-[#0B3D6E]">Partner portal</Link></aside>
        <div>
            <p v-if="$page.props.flash.success" role="status" class="success-message mb-5">{{ $page.props.flash.success }}</p>
            <div v-if="application && (readOnly || application.status !== 'draft')" class="form-card">
                <h2 class="text-2xl font-bold">Application {{ application.status }}</h2><p class="mt-3 text-slate-600">{{ application.status === 'draft' ? 'Your application is saved as a draft and is view-only in the partner portal.' : application.status === 'submitted' ? 'Your application is awaiting review. Check this page for updates.' : 'Your application has been reviewed. Read the next steps below.' }}</p>
                <p v-if="application.review_note" class="mt-5 whitespace-pre-line rounded-xl bg-slate-50 p-4">{{ application.review_note }}</p>
                <img v-if="application.has_photo" :src="route('partners.photo', application.id)" alt="Your application photo" class="h-32 w-32 object-cover"/>
                <dl class="mt-6 space-y-4"><div v-for="field in contact" :key="field.key"><dt class="font-bold">{{ field.label }}</dt><dd class="whitespace-pre-line">{{ application[field.key] || 'Not provided' }}</dd></div><div v-for="field in experience.filter(field => application[field.key])" :key="field.key"><dt class="font-bold">{{ field.label }} (previous response)</dt><dd class="whitespace-pre-line">{{ application[field.key] }}</dd></div><div><dt class="font-bold">Your interests</dt><dd class="whitespace-pre-line">{{ application.details }}</dd></div></dl>
                <PartnerAnswers class="mt-6" :questionnaire="questionnaire" :answers="application.guided_answers"/>
            </div>
            <form v-else class="form-card" @submit.prevent="submit('submit')">
                <p class="text-sm font-bold text-cyan-700">Section {{ step + 1 }} of {{ finalStep + 1 }}</p>
                <div v-show="step === 0"><h2 class="text-2xl font-bold">Contact details</h2><div class="form-grid"><label v-for="field in contact" :key="field.key" :for="field.key">{{ field.label }}<input :id="field.key" v-model="form[field.key]" :type="field.type" :maxlength="field.max"/><InputError :message="form.errors[field.key]"/></label></div></div>
                <section v-for="(section, index) in questionnaire" v-show="step === index + 1" :key="section.title" class="space-y-5">
                    <h2 class="text-2xl font-bold">{{ section.title }}</h2><p class="text-sm text-slate-600">All questions are optional. Select the answers that apply.</p>
                    <div v-for="question in section.questions" :key="question.key">
                        <label v-if="question.type === 'single'" :for="question.key">{{ question.label }}
                            <select :id="question.key" v-model="form.guided_answers[question.key]"><option :value="null">Select an option</option><option v-for="option in question.options" :key="option" :value="option">{{ option }}</option></select>
                        </label>
                        <fieldset v-else><legend class="font-semibold">{{ question.label }}</legend><p v-if="question.type === 'multiple'" class="text-sm text-slate-500">Select all that apply.</p>
                            <div class="mt-3 grid gap-3 sm:grid-cols-2"><label v-for="option in question.options" :key="option" class="!flex items-center gap-2">
                                <input v-if="question.type === 'multiple'" v-model="form.guided_answers[question.key]" type="checkbox" :value="option"/>
                                <input v-else v-model="form.guided_answers[question.key]" type="radio" :name="question.key" :value="option"/>
                                <span>{{ option }}</span>
                            </label></div>
                        </fieldset>
                        <InputError :message="form.errors[`answers.${question.key}`] || form.errors[`answers.${question.key}.0`]"/>
                    </div>
                </section>
                <div v-show="step === finalStep" class="space-y-5"><h2 class="text-2xl font-bold">Interests, photo and consent</h2><label for="partner-details">{{ category.question }}<textarea id="partner-details" v-model="form.details" rows="6" maxlength="5000"/><InputError :message="form.errors.details"/></label><img v-if="application?.has_photo" :src="route('partners.photo', application.id)" alt="Current application photo" class="h-32 w-32 object-cover"/><label for="photo">Passport-size photo (optional, JPG or PNG, up to 2 MB)<input id="photo" type="file" accept="image/jpeg,image/png" @change="form.photo = $event.target.files[0] || null"/><InputError :message="form.errors.photo"/></label><label class="!flex items-start gap-3"><input v-model="form.consent" type="checkbox" class="!w-auto mt-1"/><span>I agree that KernelBridge may use these details to review my application and contact me about this partnership.</span></label><InputError :message="form.errors.consent"/><p class="text-sm text-slate-500">Phone, location, interests and consent are required to submit. Submission does not grant staff access or confirm a commercial agreement.</p></div>
                <div v-if="application && experience.some(field => application[field.key])" class="rounded-lg bg-slate-50 p-4"><p class="font-semibold">Your previously saved experience</p><p class="text-sm">These responses remain part of your application.</p><dl class="mt-3 space-y-2"><div v-for="field in experience.filter(field => application[field.key])" :key="field.key"><dt class="font-semibold">{{ field.label }}</dt><dd class="whitespace-pre-line">{{ application[field.key] }}</dd></div></dl></div>
                <InputError :message="form.errors.answers || form.errors.guided_answers"/>
                <div class="flex flex-wrap gap-4"><button v-if="step > 0" type="button" class="rounded-xl border px-5 py-3" @click="step--">Previous</button><button type="button" class="rounded-xl border border-slate-300 px-5 py-3 font-semibold" :disabled="form.processing" @click="submit('save')">Save draft</button><button v-if="step < finalStep" type="button" class="form-button" @click="step++">Next</button><button v-else class="form-button" :disabled="form.processing">{{ form.processing ? 'Saving...' : 'Submit application' }}</button></div>
            </form>
        </div>
    </section>
</PublicLayout>
</template>
