<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import FormErrors from '@/Components/Partner/FormErrors.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
const props=defineProps({type:String,category:Object});
const form=useForm({name:'',email:'',password:'',password_confirmation:''});
const submit=()=>form.post(route('partners.register.store',props.type),{onFinish:()=>form.reset('password','password_confirmation')});
</script>
<template><Head :title="category.label+' partner registration'"/><GuestLayout><h1 class="text-2xl font-bold">{{ category.label }} registration</h1><p class="my-4 text-slate-600">Create your partner login. Use this email and password to sign in and resume onboarding.</p><form class="form-card !p-0 !shadow-none" @submit.prevent="submit"><FormErrors :errors="form.errors"/><label>Your name<input v-model="form.name" required maxlength="255" autocomplete="name"/></label><label>Email / login<input v-model="form.email" required type="email" maxlength="255" autocomplete="username"/></label><label>Password<input v-model="form.password" type="password" required autocomplete="new-password"/></label><label>Confirm password<input v-model="form.password_confirmation" type="password" required autocomplete="new-password"/></label><button class="form-button" :disabled="form.processing">Create partner login</button></form><p class="mt-6 text-sm">Already registered? <Link :href="route('login')" class="font-bold underline">Sign in</Link></p><Link :href="route('partners.index')" class="mt-4 block text-sm">Back to partner programmes</Link></GuestLayout></template>
