<script setup>
import FormErrors from '@/Components/Partner/FormErrors.vue';
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({ application: Object, questionnaire: Array });
const emit = defineEmits(['saved', 'cancel']);
const fields = [
    ['background_knowledge', 'Background knowledge'],
    ['digital_skills', 'Digital skills and experience'],
    ['installation_experience', 'Software and hardware installation'],
    ['networking_experience', 'Networking and internet'],
    ['business_skills', 'Business skills'],
    ['management_skills', 'Management skills'],
];
const previousFields = fields.filter(([key]) => props.application[key]?.trim());
const form = useForm({
    organisation: props.application.organisation || '',
    phone: props.application.phone || '',
    location: props.application.location || '',
    website: props.application.website || '',
    details: props.application.details || '',
    ...Object.fromEntries(previousFields.map(([key]) => [key, props.application[key]])),
    guided_answers: props.application.guided_answers,
});
const answers = ref(Object.fromEntries(props.questionnaire.flatMap(section => section.questions.map(question => [
    question.key, props.application.guided_answers?.[question.key] ?? (question.type === 'multiple' ? [] : null),
]))));
const initialAnswers = JSON.stringify(answers.value);
const submit = () => {
    if (JSON.stringify(answers.value) !== initialAnswers) form.guided_answers = answers.value;
    form.patch(route('admin.partners.details.update', props.application.id), {
        preserveScroll: true,
        onSuccess: () => emit('saved'),
    });
};
</script>

<template>
    <form class="form-card mt-5" @submit.prevent="submit">
        <h3 class="text-xl font-bold">Edit application details</h3>
        <p class="text-sm text-slate-600">Account name and email, application status, consent and review decisions are not changed here.</p>
        <FormErrors :errors="form.errors" />
        <div class="form-grid">
            <label>Organization<input v-model="form.organisation" maxlength="255" /></label>
            <label>Phone<input v-model="form.phone" maxlength="30" :required="application.status !== 'draft'" /></label>
            <label>Location<input v-model="form.location" maxlength="255" :required="application.status !== 'draft'" /></label>
            <label>Website<input v-model="form.website" type="url" maxlength="500" /></label>
        </div>
        <label>Interests and details<textarea v-model="form.details" maxlength="5000" rows="4" :required="application.status !== 'draft'" /></label>
        <section v-if="previousFields.length">
            <h4 class="mb-3 font-bold">Previous open-ended responses</h4>
            <div class="form-grid">
                <label v-for="[key, label] in previousFields" :key="key">{{ label }}<textarea v-model="form[key]" maxlength="2000" rows="3" /></label>
            </div>
        </section>
        <section v-for="section in questionnaire" :key="section.title" class="space-y-4">
            <h4 class="font-bold">{{ section.title }}</h4>
            <div v-for="question in section.questions" :key="question.key">
                <fieldset v-if="question.type === 'multiple'">
                    <legend class="text-sm font-bold">{{ question.label }}</legend>
                    <div class="mt-2 grid gap-2 sm:grid-cols-2">
                        <label v-for="option in question.options" :key="option" class="!flex items-center gap-2">
                            <input v-model="answers[question.key]" type="checkbox" :value="option" class="!mt-0" />{{ option }}
                        </label>
                    </div>
                </fieldset>
                <label v-else>{{ question.label }}
                    <select v-model="answers[question.key]"><option :value="null">Not provided</option><option v-for="option in question.options" :key="option" :value="option">{{ option }}</option></select>
                </label>
            </div>
        </section>
        <div class="flex flex-wrap gap-3">
            <button class="form-button" :disabled="form.processing">Save details</button>
            <button type="button" class="rounded-xl border px-5 py-3 font-semibold" @click="emit('cancel')">Cancel</button>
        </div>
    </form>
</template>
