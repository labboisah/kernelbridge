<script setup>
import AdminNavigation from '@/Components/Admin/AdminNavigation.vue';
import InputError from '@/Components/InputError.vue';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({ teams: Array, staff: Array });
const showCreate = ref(false);
const editing = ref(null);
const addingMember = ref(null);
const teamForm = useForm({ name: '', slug: '', description: '', is_active: true, sort_order: 0 });
const memberForm = useForm({ user_public_id: '' });

const openCreate = () => { editing.value = null; teamForm.reset(); teamForm.clearErrors(); showCreate.value = true; };
const openEdit = (team) => { showCreate.value = false; editing.value = team; teamForm.clearErrors(); Object.assign(teamForm, { name: team.name, slug: team.slug, description: team.description || '', is_active: team.is_active, sort_order: team.sort_order }); };
const closeForm = () => { showCreate.value = false; editing.value = null; teamForm.clearErrors(); };
const save = () => {
    const options = { preserveScroll: true, onSuccess: closeForm };
    editing.value ? teamForm.put(route('admin.support-teams.update', editing.value.uuid), options) : teamForm.post(route('admin.support-teams.store'), options);
};
const remove = (team) => confirm(`Delete ${team.name}?`) && router.delete(route('admin.support-teams.destroy', team.uuid), { preserveScroll: true });
const openMembers = (team) => { addingMember.value = team; memberForm.reset(); memberForm.clearErrors(); };
const addMember = () => memberForm.post(route('admin.support-teams.members.store', addingMember.value.uuid), { preserveScroll: true, onSuccess: () => { addingMember.value = null; memberForm.reset(); } });
const removeMember = (team, member) => confirm(`Remove ${member.name} from ${team.name}?`) && router.delete(route('admin.support-teams.members.destroy', [team.uuid, member.public_id]), { preserveScroll: true });
const availableStaff = (team) => props.staff.filter(user => !team.members.some(member => member.id === user.id));
</script>

<template>
    <Head title="Support teams" />
    <PortalLayout area="Administration">
        <template #navigation><AdminNavigation active="support-teams" /></template>
        <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"><div><p class="text-sm font-bold uppercase text-cyan-700">Support routing</p><h1 class="mt-1 text-3xl font-bold">Support teams</h1><p class="mt-2 text-slate-600">Manage ticket routing teams and their staff members.</p></div><button class="form-button" @click="openCreate">New team</button></div>
        <p v-if="$page.props.flash.success" class="success-message mt-5">{{ $page.props.flash.success }}</p>

        <section v-if="showCreate || editing" class="form-card mt-6"><div class="flex items-center justify-between"><h2 class="text-xl font-bold">{{ editing ? `Edit ${editing.name}` : 'New support team' }}</h2><button type="button" class="text-sm font-bold text-slate-500" @click="closeForm">Close</button></div><form class="mt-5" @submit.prevent="save"><div class="form-grid"><label>Name<input v-model="teamForm.name" required /><InputError :message="teamForm.errors.name" /></label><label>Slug<input v-model="teamForm.slug" required placeholder="technical" /><InputError :message="teamForm.errors.slug" /></label><label>Display order<input v-model.number="teamForm.sort_order" type="number" min="0" required /><InputError :message="teamForm.errors.sort_order" /></label><label class="flex items-center gap-3"><input v-model="teamForm.is_active" type="checkbox" /> Available when opening tickets</label></div><label>Description<textarea v-model="teamForm.description" rows="3" /><InputError :message="teamForm.errors.description" /></label><div class="flex gap-3"><button class="form-button" :disabled="teamForm.processing">{{ editing ? 'Save changes' : 'Create team' }}</button><button type="button" class="rounded-xl border px-5 py-3 font-bold" @click="closeForm">Cancel</button></div></form></section>

        <div class="mt-6 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm"><div class="hidden grid-cols-[1.2fr_.7fr_.6fr_1.4fr_auto] gap-4 border-b bg-slate-50 px-5 py-3 text-xs font-bold uppercase text-slate-500 lg:grid"><span>Team</span><span>Status</span><span>Tickets</span><span>Members</span><span>Actions</span></div><div v-for="team in teams" :key="team.uuid" class="grid gap-4 border-b border-slate-100 p-5 last:border-0 lg:grid-cols-[1.2fr_.7fr_.6fr_1.4fr_auto] lg:items-center"><div><p class="font-bold text-[#0B3D6E]">{{ team.name }}</p><p class="text-xs text-slate-400">{{ team.slug }} · order {{ team.sort_order }}</p><p v-if="team.description" class="mt-1 text-sm text-slate-600">{{ team.description }}</p></div><span class="w-fit rounded-full px-2.5 py-1 text-xs font-bold uppercase" :class="team.is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600'">{{ team.is_active ? 'Active' : 'Inactive' }}</span><span class="text-sm font-semibold">{{ team.tickets_count }}</span><div><div class="flex flex-wrap gap-1.5"><span v-for="member in team.members" :key="member.id" class="group inline-flex items-center gap-1 rounded-full bg-cyan-50 px-2.5 py-1 text-xs font-semibold text-cyan-800">{{ member.name }}<button type="button" class="text-cyan-500 hover:text-red-600" :aria-label="`Remove ${member.name}`" @click="removeMember(team, member)">×</button></span><span v-if="!team.members.length" class="text-sm text-slate-400">No members</span></div></div><div class="flex flex-wrap gap-2"><button class="rounded-lg border border-cyan-200 px-3 py-2 text-sm font-bold text-cyan-700" @click="openMembers(team)">Add member</button><button class="rounded-lg border px-3 py-2 text-sm font-bold" @click="openEdit(team)">Edit</button><button class="rounded-lg border border-red-200 px-3 py-2 text-sm font-bold text-red-700 disabled:cursor-not-allowed disabled:opacity-40" :disabled="team.tickets_count > 0" :title="team.tickets_count > 0 ? 'Deactivate teams with ticket history instead' : 'Delete team'" @click="remove(team)">Delete</button></div></div><p v-if="!teams.length" class="p-10 text-center text-slate-500">No support teams have been created.</p></div>

        <div v-if="addingMember" class="fixed inset-0 z-50 grid place-items-center bg-slate-950/50 p-4" role="dialog" aria-modal="true" aria-labelledby="member-dialog-title" @click.self="addingMember=null"><form class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-2xl" @submit.prevent="addMember"><div class="flex justify-between gap-4"><div><h2 id="member-dialog-title" class="text-xl font-bold">Add member to {{ addingMember.name }}</h2><p class="mt-1 text-sm text-slate-500">Only staff with support management access are available.</p></div><button type="button" aria-label="Close" @click="addingMember=null">×</button></div><label class="mt-5 block">Staff member<select v-model="memberForm.user_public_id" required><option value="" disabled>Select staff member</option><option v-for="user in availableStaff(addingMember)" :key="user.public_id" :value="user.public_id">{{ user.name }} — {{ user.email }}</option></select><InputError :message="memberForm.errors.user_public_id" /></label><p v-if="!availableStaff(addingMember).length" class="mt-3 text-sm text-amber-700">All eligible staff are already members of this team.</p><div class="mt-6 flex justify-end gap-3"><button type="button" class="rounded-xl border px-5 py-3 font-bold" @click="addingMember=null">Cancel</button><button class="form-button" :disabled="memberForm.processing || !availableStaff(addingMember).length">Add member</button></div></form></div>
    </PortalLayout>
</template>

<style scoped>
@media (min-width: 1024px) {
    .mt-6.overflow-hidden > div {
        grid-template-columns: minmax(0, 1.2fr) minmax(7rem, .7fr) minmax(5rem, .6fr) minmax(0, 1.4fr) 17rem !important;
    }
}
</style>
