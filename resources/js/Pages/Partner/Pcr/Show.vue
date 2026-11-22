<script setup>
import PartnerLayout from '@/Layouts/PartnerLayout.vue';
import FormErrors from '@/Components/Partner/FormErrors.vue';
import { Link, useForm } from '@inertiajs/vue3';

const props = defineProps({ prospect: Object });
const form = useForm({
    outcome: 'interested',
    consent: false,
    consent_method: 'verbal',
    consented_by_name: props.prospect.name,
    consent_details: '',
    contact_notes: '',
});
const label = status => status.replaceAll('_', ' ');
const submit = () => form.put(route('partner.pcr.contact', props.prospect.uuid), { preserveScroll: true });
</script>

<template>
    <PartnerLayout :title="prospect.organization || prospect.name">
        <Link :href="route('partner.pcr.index')" class="mb-5 inline-block font-bold text-cyan-700">Back to PCR register</Link>
        <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_420px]">
            <section class="space-y-5 rounded-2xl bg-white p-6">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="text-sm text-slate-500">PCR · {{ prospect.uuid }}</p>
                        <h2 class="mt-2 text-xl font-bold">{{ prospect.name }}</h2>
                        <p class="text-slate-600">{{ prospect.organization || 'Individual prospect' }}</p>
                    </div>
                    <span class="rounded-full bg-slate-100 px-3 py-1 text-sm capitalize">{{ label(prospect.status) }}</span>
                </div>
                <dl class="grid gap-4 sm:grid-cols-2">
                    <div><dt class="text-sm font-semibold text-slate-500">Email</dt><dd>{{ prospect.email || 'Not provided' }}</dd></div>
                    <div><dt class="text-sm font-semibold text-slate-500">Phone</dt><dd>{{ prospect.phone || 'Not provided' }}</dd></div>
                    <div><dt class="text-sm font-semibold text-slate-500">Location</dt><dd>{{ prospect.location || 'Not provided' }}</dd></div>
                    <div><dt class="text-sm font-semibold text-slate-500">Organisation type</dt><dd>{{ prospect.organization_type || 'Not provided' }}</dd></div>
                    <div><dt class="text-sm font-semibold text-slate-500">Estimated users</dt><dd>{{ prospect.number_of_users || 'Not provided' }}</dd></div>
                    <div><dt class="text-sm font-semibold text-slate-500">Product of interest</dt><dd>{{ prospect.product?.name || 'Not decided' }}</dd></div>
                    <div><dt class="text-sm font-semibold text-slate-500">Follow-up date</dt><dd>{{ prospect.follow_up_date || 'Not scheduled' }}</dd></div>
                    <div><dt class="text-sm font-semibold text-slate-500">Registered</dt><dd>{{ new Date(prospect.created_at).toLocaleString() }}</dd></div>
                </dl>
                <div v-if="prospect.initial_notes"><h3 class="font-semibold">Initial notes</h3><p class="mt-1 whitespace-pre-line text-slate-700">{{ prospect.initial_notes }}</p></div>
                <div v-if="prospect.contact_notes"><h3 class="font-semibold">Contact notes</h3><p class="mt-1 whitespace-pre-line text-slate-700">{{ prospect.contact_notes }}</p></div>
                <dl v-if="prospect.consent_at" class="grid gap-4 border-t pt-4 sm:grid-cols-2">
                    <div><dt class="text-sm font-semibold text-slate-500">Consent recorded</dt><dd>{{ new Date(prospect.consent_at).toLocaleString() }}</dd></div>
                    <div><dt class="text-sm font-semibold text-slate-500">Consent method</dt><dd class="capitalize">{{ prospect.consent_method }}</dd></div>
                    <div><dt class="text-sm font-semibold text-slate-500">Consented by</dt><dd>{{ prospect.consented_by_name }}</dd></div>
                    <div v-if="prospect.consent_details"><dt class="text-sm font-semibold text-slate-500">Consent record</dt><dd class="whitespace-pre-line">{{ prospect.consent_details }}</dd></div>
                </dl>
                <Link v-if="prospect.converted_lead" :href="route('partner.crm.show', prospect.converted_lead.uuid)" class="inline-block font-bold text-cyan-700">Open CRM record · {{ prospect.converted_lead.lead_number }}</Link>
            </section>

            <form v-if="['prospective', 'interested_pending_consent'].includes(prospect.status)" class="form-card h-fit" @submit.prevent="submit">
                <h2 class="text-xl font-bold">Record visit &amp; consent</h2>
                <p class="mt-2 text-sm text-slate-600">Record that you contacted the prospect. Add them to the CRM only if they are interested and explicitly consent to follow-up.</p>
                <FormErrors :errors="form.errors" />
                <label>Outcome<select v-model="form.outcome"><option value="interested">Interested and consent given</option><option value="consent_pending">Interested, consent not yet given</option><option value="not_interested">Not interested</option></select></label>
                <p v-if="form.outcome === 'consent_pending'" class="rounded-lg bg-amber-50 p-3 text-sm text-amber-900">This prospect remains in the PCR and will not be added to the CRM until consent is recorded.</p>
                <template v-if="form.outcome === 'interested'">
                    <label class="!flex items-start gap-3"><input v-model="form.consent" type="checkbox" required class="mt-1 rounded border-slate-300 text-[#0B3D6E]" /><span>I confirm the prospect explicitly consented to be registered in the CRM and contacted about this business interest.</span></label>
                    <label>How was consent given?<select v-model="form.consent_method" required><option value="verbal">Verbal</option><option value="email">Email</option><option value="written">Written</option></select></label>
                    <label>Name of person giving consent<input v-model="form.consented_by_name" required maxlength="255" /></label>
                    <label>Consent reference or details<textarea v-model="form.consent_details" rows="2" maxlength="2000" placeholder="For example, date of consent or email reference; do not include unnecessary sensitive data" /></label>
                </template>
                <label>Contact notes<textarea v-model="form.contact_notes" rows="3" maxlength="5000" /></label>
                <button class="form-button" :disabled="form.processing">{{ form.outcome === 'interested' ? 'Record consent and add to CRM' : form.outcome === 'consent_pending' ? 'Save interest; consent pending' : 'Record as not interested' }}</button>
            </form>
            <section v-else-if="prospect.status === 'not_interested'" class="h-fit rounded-2xl bg-white p-6">
                <h2 class="text-xl font-bold">Not interested</h2>
                <p class="mt-2 text-slate-600">This prospect was not added to the CRM.</p>
            </section>
            <section v-else-if="prospect.status === 'converted'" class="h-fit rounded-2xl bg-white p-6">
                <h2 class="text-xl font-bold">Added to CRM</h2>
                <p class="mt-2 text-slate-600">Consent was recorded and this prospect is being tracked in the sales pipeline.</p>
            </section>
        </div>
    </PartnerLayout>
</template>
