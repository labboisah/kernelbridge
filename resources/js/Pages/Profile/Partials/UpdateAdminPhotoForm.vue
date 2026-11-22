<script setup>
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { useForm, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';

const page = usePage();
const form = useForm({ photo: null });
const input = ref(null);
const submit = () => form.post(route('profile.photo.update'), {
    forceFormData: true,
    preserveScroll: true,
    onSuccess: () => { form.reset(); input.value.value = ''; },
});
</script>

<template>
    <section>
        <h2 class="text-lg font-medium text-gray-900">Administrator photo</h2>
        <p class="mt-1 text-sm text-gray-600">Upload a JPG or PNG image up to 2 MB for your account menu.</p>
        <img v-if="page.props.auth.admin_photo_url" :src="page.props.auth.admin_photo_url" alt="Current administrator photo" class="mt-4 h-20 w-20 rounded-full object-cover" />
        <form class="mt-4 space-y-4" @submit.prevent="submit">
            <label for="admin-photo" class="block text-sm font-medium text-gray-700">Photo</label>
            <input id="admin-photo" ref="input" type="file" accept="image/jpeg,image/png" required @change="form.photo = $event.target.files[0] || null" />
            <InputError :message="form.errors.photo" />
            <div class="flex items-center gap-4">
                <PrimaryButton :disabled="form.processing || !form.photo">Upload photo</PrimaryButton>
                <p v-if="form.recentlySuccessful" role="status" class="text-sm text-gray-600">Photo saved.</p>
            </div>
        </form>
    </section>
</template>
