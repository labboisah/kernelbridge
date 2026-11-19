<script setup>
defineProps({ questionnaire: Array, answers: Object });
</script>

<template>
    <div v-if="answers" class="space-y-5">
        <section v-for="section in questionnaire" :key="section.title">
            <h3 class="font-bold">{{ section.title }}</h3>
            <dl class="mt-3 space-y-3">
                <div v-for="question in section.questions" :key="question.key">
                    <dt class="font-semibold">{{ question.label }}</dt>
                    <dd class="break-words">{{ Array.isArray(answers[question.key]) ? answers[question.key].join(', ') || 'Not provided' : answers[question.key] || 'Not provided' }}</dd>
                </div>
            </dl>
        </section>
    </div>
</template>
