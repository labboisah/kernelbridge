<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { nextTick, onMounted, ref } from 'vue';

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
const digits = ref(Array(6).fill(''));
const digitInputs = ref([]);

onMounted(() => digitInputs.value[0]?.focus());

const submit = () => {
    if (form.processing || form.code.length !== 6) {
        return;
    }

    form.post(route('login.two-factor.verify'), {
        onFinish: () => {
            form.reset('code');
            digits.value = Array(6).fill('');
            nextTick(() => digitInputs.value[0]?.focus());
        },
    });
};

const syncCode = () => {
    form.code = digits.value.join('');
};

const handleInput = (index, event) => {
    const entered = event.target.value.replace(/\D/g, '').slice(0, 6);

    if (entered.length > 1) {
        digits.value = Array(6).fill('');
        entered.split('').forEach((digit, offset) => {
            digits.value[offset] = digit;
        });
        syncCode();

        if (form.code.length === 6) {
            submit();
        } else {
            digitInputs.value[form.code.length]?.focus();
        }

        return;
    }

    digits.value[index] = entered;
    syncCode();

    if (entered && form.code.length === 6) {
        submit();
    } else if (entered) {
        digitInputs.value[index + 1]?.focus();
    }
};

const handlePaste = (index, event) => {
    const pasted = event.clipboardData.getData('text').replace(/\D/g, '').slice(0, 6);
    if (!pasted) {
        return;
    }

    event.preventDefault();
    let nextIndex = index + 1;

    if (pasted.length > 1) {
        digits.value = Array(6).fill('');
        pasted.split('').forEach((digit, offset) => {
            digits.value[offset] = digit;
        });
        nextIndex = pasted.length;
    } else {
        digits.value[index] = pasted;
    }

    syncCode();

    if (form.code.length === 6) {
        submit();
    } else {
        digitInputs.value[Math.min(nextIndex, 5)]?.focus();
    }
};

const handleKeydown = (index, event) => {
    if (event.key === 'Backspace' && !digits.value[index] && index > 0) {
        digitInputs.value[index - 1]?.focus();
    } else if (event.key === 'ArrowLeft' && index > 0) {
        event.preventDefault();
        digitInputs.value[index - 1]?.focus();
    } else if (event.key === 'ArrowRight' && index < 5) {
        event.preventDefault();
        digitInputs.value[index + 1]?.focus();
    }
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
            <InputLabel for="code-0" value="Six-digit code" />
            <div role="group" aria-label="Six-digit verification code" class="mt-2 flex justify-center gap-2 sm:gap-3">
                <input
                    v-for="(_, index) in digits"
                    :key="index"
                    :ref="(element) => (digitInputs[index] = element)"
                    :id="index === 0 ? 'code-0' : undefined"
                    :value="digits[index]"
                    :aria-label="`Digit ${index + 1} of 6`"
                    :autocomplete="index === 0 ? 'one-time-code' : 'off'"
                    type="text"
                    inputmode="numeric"
                    pattern="[0-9]*"
                    maxlength="1"
                    required
                    :disabled="form.processing"
                    class="h-12 w-11 rounded-lg border border-slate-300 text-center text-xl font-bold text-slate-900 focus:border-[#0797A5] focus:outline-none focus:ring-2 focus:ring-[#0797A5] disabled:opacity-50 sm:h-14 sm:w-12"
                    @input="handleInput(index, $event)"
                    @paste="handlePaste(index, $event)"
                    @keydown="handleKeydown(index, $event)"
                />
            </div>
            <InputError class="mt-2" :message="form.errors.code" />

            <div class="mt-6 flex items-center justify-between gap-4">
                <button
                    type="button"
                    class="text-sm font-medium text-[#0B3D6E] underline hover:text-[#0797A5] disabled:opacity-50"
                    :disabled="resendForm.processing"
                    @click="resend"
                >
                    Send a new code
                </button>

                <PrimaryButton :class="{ 'opacity-25': form.processing }" :disabled="form.processing || form.code.length !== 6">
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
