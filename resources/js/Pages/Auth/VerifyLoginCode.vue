<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps({
    email: {
        type: String,
        required: true,
    },
    status: {
        type: String,
    },
});

const form = useForm({ code: '' });
const resendForm = useForm({});

const submit = () => {
    form.post(route('login.two-factor.verify'), {
        onFinish: () => form.reset('code'),
    });
};

const resend = () => {
    resendForm.post(route('login.two-factor.resend'));
};
</script>

<template>
    <GuestLayout>
        <Head>
            <title>Verify your login | KernelBridge</title>
            <meta name="robots" content="noindex,follow" />
        </Head>

        <div class="mb-6">
            <h1 class="text-xl font-semibold text-slate-900">Verify your login</h1>
            <p class="mt-2 text-sm text-slate-600">
                Enter the six-digit code sent to <strong>{{ email }}</strong>.
                It expires in five minutes.
            </p>
        </div>

        <div v-if="status" class="mb-4 text-sm font-medium text-green-600">
            {{ status }}
        </div>

        <form @submit.prevent="submit">
            <div>
                <InputLabel for="code" value="Six-digit code" />
                <TextInput
                    id="code"
                    v-model="form.code"
                    class="mt-1 block w-full"
                    type="text"
                    inputmode="numeric"
                    pattern="[0-9]{6}"
                    maxlength="6"
                    autocomplete="one-time-code"
                    required
                    autofocus
                />
                <InputError class="mt-2" :message="form.errors.code" />
            </div>

            <div class="mt-6 flex items-center justify-between gap-4">
                <button
                    type="button"
                    class="text-sm font-medium text-[#0B3D6E] underline hover:text-[#0797A5] disabled:opacity-50"
                    :disabled="resendForm.processing"
                    @click="resend"
                >
                    Send a new code
                </button>

                <PrimaryButton :class="{ 'opacity-25': form.processing }" :disabled="form.processing">
                    Verify and continue
                </PrimaryButton>
            </div>
            <InputError class="mt-2" :message="resendForm.errors.code" />
        </form>

        <Link
            :href="route('login')"
            class="mt-6 block text-center text-sm text-slate-600 underline hover:text-slate-900"
        >
            Return to sign in
        </Link>
    </GuestLayout>
</template>
