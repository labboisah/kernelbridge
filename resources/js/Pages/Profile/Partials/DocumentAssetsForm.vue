<script setup>
import InputError from '@/Components/InputError.vue';
import { router, useForm } from '@inertiajs/vue3';

defineProps({ assets: { type: Object, required: true } });
const forms = { signature: useForm({ asset: null }), stamp: useForm({ asset: null }) };
const submit = kind => forms[kind].post(route('profile.document-assets.update', kind), {
    forceFormData: true,
    preserveScroll: true,
    onSuccess: () => { forms[kind].reset(); document.getElementById(`document-${kind}`).value = ''; },
});
const remove = kind => {
    if (window.confirm(`Remove the company ${kind} from future document renders?`)) {
        router.delete(route('profile.document-assets.destroy', kind), { preserveScroll: true });
    }
};
</script>

<template>
    <section>
        <h2 class="text-lg font-medium text-gray-900">Company document images</h2>
        <p class="mt-1 text-sm text-gray-600">These shared images are automatically included in supported documents, including partner quotations. JPG or PNG, up to 2 MB each. Replacing or removing an image affects documents rendered later.</p>
        <div v-for="kind in ['signature', 'stamp']" :key="kind" class="mt-5 border-t pt-4">
            <h3 class="font-semibold capitalize">{{ kind }}</h3>
            <img v-if="assets[kind]" :src="assets[kind]" :alt="`Current company ${kind}`" class="mt-2 max-h-24 max-w-40 object-contain" />
            <form class="mt-3 space-y-3" @submit.prevent="submit(kind)">
                <label :for="`document-${kind}`" class="block text-sm font-medium">Upload {{ kind }}</label>
                <input :id="`document-${kind}`" type="file" accept="image/jpeg,image/png" required @change="forms[kind].asset = $event.target.files[0] || null" />
                <InputError :message="forms[kind].errors.asset" />
                <div class="flex flex-wrap gap-3">
                    <button class="form-button" :disabled="forms[kind].processing || !forms[kind].asset">Upload {{ kind }}</button>
                    <button v-if="assets[kind]" type="button" class="rounded-lg border border-red-300 px-4 py-2 text-red-700" @click="remove(kind)">Remove {{ kind }}</button>
                </div>
            </form>
        </div>
    </section>
</template>
