<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import FormErrors from '@/Components/Partner/FormErrors.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    token: String, customerName: String, email: String, signedIn: Boolean,
    matchingUser: Boolean, verified: Boolean, existingAccount: Boolean,
});
const form = useForm({ name: '', password: '', password_confirmation: '' });
const accept = () => form.post(route('client-invitations.accept', props.token), {
    onFinish: () => form.reset('password', 'password_confirmation'),
});
</script>

<template>
    <GuestLayout>
        <Head title="Customer account invitation" />
        <h1 class="text-xl font-bold">Customer account invitation</h1>
        <p class="mt-3 text-sm text-slate-600">Link access to {{ customerName }} using {{ email }}. This invitation is valid for seven days.</p>
        <FormErrors :errors="form.errors" />
        <div v-if="signedIn && !matchingUser" class="mt-4 text-sm">
            <p>Sign out and reopen the invitation to use the invited email address.</p>
            <Link :href="route('logout')" method="post" as="button" class="mt-3 font-semibold text-cyan-700">Sign out</Link>
        </div>
        <div v-else-if="signedIn && !verified" class="mt-4 text-sm">
            <p>Verify your email address before accepting this invitation.</p>
            <Link :href="route('verification.notice')" class="mt-3 inline-block font-semibold text-cyan-700">Verify email</Link>
        </div>
        <div v-else-if="!signedIn && existingAccount" class="mt-4 text-sm">
            <p>An account already exists for this email. Sign in to link it to this client account.</p>
            <Link :href="route('login')" class="mt-3 inline-block font-semibold text-cyan-700">Sign in</Link>
        </div>
        <form v-else class="mt-4 space-y-4" @submit.prevent="accept">
            <template v-if="!signedIn">
                <label class="block">Your name<input v-model="form.name" class="mt-1 block w-full rounded-md border-slate-300" required maxlength="255" autocomplete="name" /></label>
                <label class="block">Password<input v-model="form.password" class="mt-1 block w-full rounded-md border-slate-300" type="password" required autocomplete="new-password" /></label>
                <label class="block">Confirm password<input v-model="form.password_confirmation" class="mt-1 block w-full rounded-md border-slate-300" type="password" required autocomplete="new-password" /></label>
            </template>
            <button type="submit" class="rounded-lg bg-[#0B3D6E] px-4 py-2 font-semibold text-white" :disabled="form.processing">Accept invitation</button>
        </form>
    </GuestLayout>
</template>
