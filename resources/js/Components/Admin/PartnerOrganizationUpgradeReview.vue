<script setup>
import { useForm } from '@inertiajs/vue3';
import InputError from '@/Components/InputError.vue';

const props = defineProps({ upgrade: { type: Object, required: true } });
const form = useForm({ status: 'approved', review_note: '' });
const businessTypes = {
    private_limited: 'Private limited company',
    public_limited: 'Public limited company',
    partnership: 'Partnership',
    cooperative: 'Cooperative',
    nonprofit: 'Non-profit',
    sole_proprietorship: 'Sole proprietorship',
    other: 'Other',
};
</script>

<template>
    <article class="rounded-2xl border bg-white p-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h3 class="text-xl font-bold">{{ upgrade.business_legal_name }}</h3>
                <p class="mt-1 text-slate-600">{{ upgrade.partner.name }} · {{ upgrade.partner.email }}</p>
            </div>
            <span class="rounded-full bg-slate-100 px-3 py-1 text-sm capitalize">{{ upgrade.status }}</span>
        </div>

        <div class="mt-5 grid gap-6 lg:grid-cols-2">
            <section>
                <h4 class="font-bold">Corporate information</h4>
                <dl class="mt-3 grid gap-3 sm:grid-cols-2">
                    <div><dt class="text-sm text-slate-500">Business type</dt><dd>{{ businessTypes[upgrade.business_type] || upgrade.business_type }}</dd></div>
                    <div><dt class="text-sm text-slate-500">Registration</dt><dd>{{ upgrade.registration_number }} · {{ upgrade.registration_country }}</dd></div>
                    <div><dt class="text-sm text-slate-500">Incorporated</dt><dd>{{ upgrade.incorporation_date || 'Not provided' }}</dd></div>
                    <div><dt class="text-sm text-slate-500">Tax ID</dt><dd>{{ upgrade.tax_id || 'Not provided' }}</dd></div>
                    <div><dt class="text-sm text-slate-500">Business email</dt><dd>{{ upgrade.business_email }}</dd></div>
                    <div><dt class="text-sm text-slate-500">Business phone</dt><dd>{{ upgrade.business_phone }}</dd></div>
                    <div class="sm:col-span-2"><dt class="text-sm text-slate-500">Website</dt><dd>{{ upgrade.website || 'Not provided' }}</dd></div>
                    <div class="sm:col-span-2"><dt class="text-sm text-slate-500">Registered address</dt><dd>{{ upgrade.registered_address }}, {{ upgrade.city }}, {{ upgrade.state_region }}, {{ upgrade.country }}</dd></div>
                </dl>
            </section>

            <section>
                <h4 class="font-bold">Authorized representative</h4>
                <dl class="mt-3 grid gap-3 sm:grid-cols-2">
                    <div><dt class="text-sm text-slate-500">Name</dt><dd>{{ upgrade.representative_name }}</dd></div>
                    <div><dt class="text-sm text-slate-500">Title</dt><dd>{{ upgrade.representative_title }}</dd></div>
                    <div><dt class="text-sm text-slate-500">Email</dt><dd>{{ upgrade.representative_email }}</dd></div>
                    <div><dt class="text-sm text-slate-500">Phone</dt><dd>{{ upgrade.representative_phone }}</dd></div>
                </dl>
                <h4 class="mt-5 font-bold">Ownership and control</h4>
                <div class="mt-2 space-y-2">
                    <p v-for="owner in upgrade.ownership_details" :key="`${owner.full_name}-${owner.role}`" class="rounded-lg bg-slate-50 p-3">
                        <strong>{{ owner.full_name }}</strong> · {{ owner.role }} · {{ owner.ownership_percentage }}%
                        <span v-if="owner.country"> · {{ owner.country }}</span>
                    </p>
                </div>
            </section>
        </div>
        <section class="mt-5 grid gap-4 sm:grid-cols-2">
            <div><h4 class="font-bold">Business activity</h4><p class="mt-1 whitespace-pre-line">{{ upgrade.business_activity }}</p></div>
            <div><h4 class="font-bold">Partnership goals</h4><p class="mt-1 whitespace-pre-line">{{ upgrade.partnership_goals }}</p></div>
        </section>
        <section class="mt-5">
            <h4 class="font-bold">Supporting documents</h4>
            <ul class="mt-2 space-y-2">
                <li v-for="[key, label] in [['registration', 'Proof of registration'], ['identity', 'Owner identity'], ['address', 'Proof of business address'], ['office_photo_one', 'Office photo 1'], ['office_photo_two', 'Office photo 2']]" :key="key">
                    <a v-if="upgrade.documents[key]" :href="route('admin.partners.organization-upgrades.documents', { upgrade: upgrade.id, document: key })" class="font-semibold text-cyan-700 underline">{{ label }}</a>
                    <span v-else class="text-slate-500">{{ label }}: not provided</span>
                </li>
            </ul>
        </section>
        <p v-if="upgrade.review_note" class="mt-5 whitespace-pre-line rounded-lg bg-slate-50 p-4">{{ upgrade.review_note }}</p>

        <form v-if="upgrade.status === 'submitted'" class="form-card mt-6 !shadow-none" @submit.prevent="form.put(route('admin.partners.organization-upgrades.review', upgrade.id), { preserveScroll: true })">
            <label>Decision<select v-model="form.status"><option value="approved">Approve organization upgrade</option><option value="declined">Decline</option></select><InputError :message="form.errors.status" /></label>
            <label>Review note for partner<textarea v-model="form.review_note" required maxlength="2000" rows="3" /><InputError :message="form.errors.review_note" /></label>
            <button class="form-button" :disabled="form.processing">{{ form.processing ? 'Saving...' : 'Save review decision' }}</button>
        </form>
        <p v-else-if="upgrade.reviewer" class="mt-4 text-sm text-slate-500">Reviewed by {{ upgrade.reviewer.name }} on {{ new Date(upgrade.reviewed_at).toLocaleString() }}</p>
    </article>
</template>
