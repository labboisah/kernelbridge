<script setup>
import { useForm } from '@inertiajs/vue3';
import PartnerLayout from '@/Layouts/PartnerLayout.vue';
import FormErrors from '@/Components/Partner/FormErrors.vue';
import InputError from '@/Components/InputError.vue';

const props = defineProps({ upgrade: Object, readOnly: Boolean });
const ownerRoles = ['Director', 'Shareholder', 'Director and shareholder', 'Partner', 'Sole proprietor', 'Trustee', 'Beneficial owner'];
const form = useForm({
    business_legal_name: props.upgrade?.business_legal_name || '',
    registration_number: props.upgrade?.registration_number || '',
    registration_country: props.upgrade?.registration_country || '',
    business_type: props.upgrade?.business_type || '',
    incorporation_date: props.upgrade?.incorporation_date || '',
    tax_id: props.upgrade?.tax_id || '',
    website: props.upgrade?.website || '',
    business_email: props.upgrade?.business_email || '',
    business_phone: props.upgrade?.business_phone || '',
    registered_address: props.upgrade?.registered_address || '',
    city: props.upgrade?.city || '',
    state_region: props.upgrade?.state_region || '',
    country: props.upgrade?.country || '',
    representative_name: props.upgrade?.representative_name || '',
    representative_title: props.upgrade?.representative_title || '',
    representative_email: props.upgrade?.representative_email || '',
    representative_phone: props.upgrade?.representative_phone || '',
    ownership_details: props.upgrade?.ownership_details?.length
        ? props.upgrade.ownership_details.map(owner => ({
            ...owner,
            role: ownerRoles.includes(owner.role) ? owner.role : 'Other',
            other_role: ownerRoles.includes(owner.role) ? '' : owner.role,
        }))
        : [{ full_name: '', role: '', other_role: '', ownership_percentage: '', country: '' }],
    business_activity: props.upgrade?.business_activity || '',
    partnership_goals: props.upgrade?.partnership_goals || '',
    registration_proof: null,
    owner_identity: null,
    address_proof: null,
    office_photo_one: null,
    office_photo_two: null,
});
const addOwner = () => form.ownership_details.push({ full_name: '', role: '', other_role: '', ownership_percentage: '', country: '' });
const removeOwner = index => form.ownership_details.splice(index, 1);
const submit = () => form.transform(data => ({
    ...data,
    ownership_details: data.ownership_details.map(({ other_role, ...owner }) => ({
        ...owner,
        role: owner.role === 'Other' ? other_role : owner.role,
    })),
})).post(route('partner.organization-upgrade.store'), { preserveScroll: true });
</script>

<template>
    <PartnerLayout title="Upgrade to organization partner">
        <p class="mb-6 max-w-3xl text-slate-600">Submit your organization's corporate and ownership information for review. Your account remains an individual partner until the upgrade is approved. Approval changes the commission rate used for future eligible sales; previously earned commissions are not recalculated.</p>

        <section v-if="upgrade?.status === 'submitted'" class="form-card max-w-4xl">
            <h2 class="text-xl font-bold">Upgrade request submitted</h2>
            <p class="mt-2 text-slate-600">Your request was submitted {{ new Date(upgrade.submitted_at).toLocaleString() }} and is awaiting administrator review.</p>
        </section>
        <section v-else-if="upgrade?.status === 'declined'" class="mb-6 max-w-4xl rounded-xl bg-amber-50 p-5 text-amber-950">
            <h2 class="font-bold">Previous request declined</h2>
            <p v-if="upgrade.review_note" class="mt-2 whitespace-pre-line">{{ upgrade.review_note }}</p>
            <p class="mt-2">You can update the organization details and submit a new request.</p>
        </section>

        <form v-if="!readOnly" class="form-card max-w-4xl space-y-6" @submit.prevent="submit">
            <FormErrors :errors="form.errors" />
            <section>
                <h2 class="mb-4 text-xl font-bold">Organization information</h2>
                <div class="form-grid">
                    <label>Legal business name<input v-model="form.business_legal_name" required maxlength="255" /><InputError :message="form.errors.business_legal_name" /></label>
                    <label>Business type<select v-model="form.business_type" required><option value="">Select type</option><option value="private_limited">Private limited company</option><option value="public_limited">Public limited company</option><option value="partnership">Partnership</option><option value="cooperative">Cooperative</option><option value="nonprofit">Non-profit</option><option value="sole_proprietorship">Sole proprietorship</option><option value="other">Other</option></select><InputError :message="form.errors.business_type" /></label>
                    <label>Registration number<input v-model="form.registration_number" required maxlength="100" /><InputError :message="form.errors.registration_number" /></label>
                    <label>Registration country<input v-model="form.registration_country" required maxlength="100" /><InputError :message="form.errors.registration_country" /></label>
                    <label>Incorporation date<input v-model="form.incorporation_date" type="date" /><InputError :message="form.errors.incorporation_date" /></label>
                    <label>Tax identification number (optional)<input v-model="form.tax_id" maxlength="100" /><InputError :message="form.errors.tax_id" /></label>
                    <label>Business email<input v-model="form.business_email" type="email" required maxlength="255" /><InputError :message="form.errors.business_email" /></label>
                    <label>Business phone<input v-model="form.business_phone" type="tel" required maxlength="40" /><InputError :message="form.errors.business_phone" /></label>
                    <label>Website (optional)<input v-model="form.website" type="url" maxlength="500" /><InputError :message="form.errors.website" /></label>
                </div>
            </section>

            <section>
                <h2 class="mb-4 text-xl font-bold">Registered business address</h2>
                <div class="form-grid">
                    <label class="sm:col-span-2">Street address<textarea v-model="form.registered_address" required maxlength="1000" rows="2" /><InputError :message="form.errors.registered_address" /></label>
                    <label>City<input v-model="form.city" required maxlength="100" /><InputError :message="form.errors.city" /></label>
                    <label>State / region<input v-model="form.state_region" required maxlength="100" /><InputError :message="form.errors.state_region" /></label>
                    <label>Country<input v-model="form.country" required maxlength="100" /><InputError :message="form.errors.country" /></label>
                </div>
            </section>

            <section>
                <h2 class="mb-4 text-xl font-bold">Authorized representative</h2>
                <div class="form-grid">
                    <label>Full name<input v-model="form.representative_name" required maxlength="255" /><InputError :message="form.errors.representative_name" /></label>
                    <label>Position / title<input v-model="form.representative_title" required maxlength="150" /><InputError :message="form.errors.representative_title" /></label>
                    <label>Email<input v-model="form.representative_email" type="email" required maxlength="255" /><InputError :message="form.errors.representative_email" /></label>
                    <label>Phone<input v-model="form.representative_phone" type="tel" required maxlength="40" /><InputError :message="form.errors.representative_phone" /></label>
                </div>
            </section>

            <section>
                <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <div><h2 class="text-xl font-bold">Ownership and control</h2><p class="mt-1 text-sm text-slate-600">List each owner or controlling person. Ownership percentages must total 100%.</p></div>
                    <button type="button" class="rounded-lg border px-3 py-2 font-semibold" @click="addOwner">Add owner</button>
                </div>
                <InputError :message="form.errors.ownership_details" />
                <div v-for="(owner, index) in form.ownership_details" :key="index" class="mb-4 rounded-xl border p-4">
                    <div class="form-grid">
                        <label>Owner / controller full name<input v-model="owner.full_name" required maxlength="255" /><InputError :message="form.errors[`ownership_details.${index}.full_name`]" /></label>
                        <label>Role<select v-model="owner.role" required><option value="">Select role</option><option v-for="role in ownerRoles" :key="role" :value="role">{{ role }}</option><option value="Other">Other</option></select><InputError :message="form.errors[`ownership_details.${index}.role`]" /></label>
                        <label v-if="owner.role === 'Other'">Specify role<input v-model.trim="owner.other_role" required maxlength="150" /><InputError :message="form.errors[`ownership_details.${index}.role`]" /></label>
                        <label>Ownership percentage<input v-model="owner.ownership_percentage" type="number" min="0.01" max="100" step="0.01" required /><InputError :message="form.errors[`ownership_details.${index}.ownership_percentage`]" /></label>
                        <label>Country of residence (optional)<input v-model="owner.country" maxlength="100" /><InputError :message="form.errors[`ownership_details.${index}.country`]" /></label>
                    </div>
                    <button v-if="form.ownership_details.length > 1" type="button" class="mt-3 text-sm font-semibold text-red-700" @click="removeOwner(index)">Remove owner</button>
                </div>
            </section>

            <section>
                <h2 class="mb-4 text-xl font-bold">Business and partnership</h2>
                <div class="space-y-4">
                    <label>Business activity<textarea v-model="form.business_activity" required maxlength="3000" rows="3" placeholder="Describe the organization's main products or services." /><InputError :message="form.errors.business_activity" /></label>
                    <label>Partnership goals<textarea v-model="form.partnership_goals" required maxlength="3000" rows="3" placeholder="Describe your proposed partnership activities, reach, and support needs." /><InputError :message="form.errors.partnership_goals" /></label>
                </div>
            </section>

            <section>
                <h2 class="mb-4 text-xl font-bold">Supporting documents</h2>
                <p class="mb-4 text-sm text-slate-600">Upload each document as a PDF, JPG, PNG or WebP file (maximum 5 MB each). These documents are available only to authorized administrators.</p>
                <div class="space-y-4">
                    <label>Proof of business registration<input type="file" accept=".pdf,.jpg,.jpeg,.png,.webp" required @change="form.registration_proof = $event.target.files[0] || null" /><InputError :message="form.errors.registration_proof" /></label>
                    <label>Owner identity document<input type="file" accept=".pdf,.jpg,.jpeg,.png,.webp" required @change="form.owner_identity = $event.target.files[0] || null" /><InputError :message="form.errors.owner_identity" /></label>
                    <label>Proof of business address (electricity, water or other utility bill)<input type="file" accept=".pdf,.jpg,.jpeg,.png,.webp" required @change="form.address_proof = $event.target.files[0] || null" /><InputError :message="form.errors.address_proof" /></label>
                </div>
            </section>

            <section>
                <h2 class="mb-4 text-xl font-bold">Business office photos</h2>
                <p class="mb-4 text-sm text-slate-600">Upload two photos of your business office facility (JPG, PNG or WebP, maximum 5 MB each). These photos are available only to authorized administrators.</p>
                <div class="space-y-4">
                    <label>Office photo 1<input type="file" accept=".jpg,.jpeg,.png,.webp" required @change="form.office_photo_one = $event.target.files[0] || null" /><InputError :message="form.errors.office_photo_one" /></label>
                    <label>Office photo 2<input type="file" accept=".jpg,.jpeg,.png,.webp" required @change="form.office_photo_two = $event.target.files[0] || null" /><InputError :message="form.errors.office_photo_two" /></label>
                </div>
            </section>

            <p class="text-sm text-slate-600">Submit accurate information on behalf of the organization. Corporate and ownership details will be visible to authorized administrators reviewing this request.</p>
            <button class="form-button" :disabled="form.processing">{{ form.processing ? 'Submitting...' : 'Submit organization upgrade request' }}</button>
        </form>
    </PartnerLayout>
</template>
