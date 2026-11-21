<script setup>
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Card from '@/Components/Ui/Card.vue';
import Button from '@/Components/Ui/Button.vue';
import Input from '@/Components/Ui/Input.vue';
import Select from '@/Components/Ui/Select.vue';
import Badge from '@/Components/Ui/Badge.vue';
import ConfirmDialog from '@/Components/Ui/ConfirmDialog.vue';

const props = defineProps({
  club: Object,
  people: { type: Array, default: () => [] },
  officeRoles: { type: Object, default: () => ({}) },
  roles: { type: Array, default: () => [] },
});

// The coach role is shown as "Secretary", as on the other role screens.
const roleLabel = r => (r === 'coach' ? 'Secretary' : r.charAt(0).toUpperCase() + r.slice(1));
const roleOptions = computed(() => props.roles.map(r => ({ value: r, label: roleLabel(r) })));

const invite = useForm({ name: '', email: '', office: '', role: 'coach' });
// A familiar office pre-selects its usual role; the role can still be changed.
function officeChanged() {
  invite.role = props.officeRoles[invite.office] ?? 'coach';
}
function sendInvite() {
  invite.post(route('admin.board.store', { clubSlug: props.club.slug }), { preserveScroll: true, onSuccess: () => invite.reset() });
}

const editing = ref(null);
const edit = useForm({ office: '', role: 'coach' });
function startEdit(person) {
  editing.value = person.id;
  edit.office = person.office ?? '';
  edit.role = person.role;
}
function saveEdit(person) {
  edit.put(route('admin.board.update', { clubSlug: props.club.slug, userId: person.id }), { preserveScroll: true, onSuccess: () => (editing.value = null) });
}

const removing = ref(null);
function confirmRemove() {
  router.delete(route('admin.board.destroy', { clubSlug: props.club.slug, userId: removing.value.id }), {
    preserveScroll: true,
    onFinish: () => (removing.value = null),
  });
}
</script>

<template>
  <Head title="Board" />
  <AdminLayout title="Board">
    <div class="max-w-3xl mx-auto space-y-4">

      <Card v-for="person in people" :key="person.id" class="p-5">
        <div v-if="editing !== person.id" class="flex items-center justify-between gap-3">
          <div>
            <div class="font-semibold">{{ person.name }} <Badge v-if="person.pending">Invited</Badge></div>
            <div class="text-sm text-slate-600">{{ person.office || 'No office' }} · {{ roleLabel(person.role) }} · {{ person.email }}</div>
          </div>
          <div class="flex gap-2">
            <Button variant="secondary" size="sm" @click="startEdit(person)">Edit</Button>
            <Button variant="danger" size="sm" @click="removing = person">Remove</Button>
          </div>
        </div>
        <form v-else class="grid gap-3 sm:grid-cols-3 items-end" @submit.prevent="saveEdit(person)">
          <Input v-model="edit.office" label="Office" :error="edit.errors.office" required />
          <Select v-model="edit.role" label="Access role" :options="roleOptions" :error="edit.errors.role" />
          <div class="flex gap-2">
            <Button type="submit" :loading="edit.processing">Save</Button>
            <Button variant="secondary" @click="editing = null">Cancel</Button>
          </div>
        </form>
      </Card>

      <Card class="p-5">
        <h2 class="font-semibold mb-3">Invite someone</h2>
        <form class="grid gap-3 sm:grid-cols-2" @submit.prevent="sendInvite">
          <Input v-model="invite.name" label="Name" :error="invite.errors.name" required />
          <Input v-model="invite.email" type="email" label="Email" :error="invite.errors.email" required />
          <Input v-model="invite.office" label="Office" list="board-offices" hint="For example Chairman, Secretary or Treasurer" :error="invite.errors.office" required @update:model-value="officeChanged" />
          <Select v-model="invite.role" label="Access role" :options="roleOptions" :error="invite.errors.role" />
          <datalist id="board-offices"><option v-for="(_, office) in officeRoles" :key="office" :value="office" /></datalist>
          <div class="sm:col-span-2"><Button type="submit" :loading="invite.processing">Send invitation</Button></div>
        </form>
      </Card>
    </div>

    <ConfirmDialog :open="!!removing" title="Remove from the board" confirm-label="Remove" @close="removing = null" @confirm="confirmRemove">
      {{ removing?.name }} will lose access to this hall.
    </ConfirmDialog>
  </AdminLayout>
</template>
