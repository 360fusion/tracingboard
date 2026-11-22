<script setup>
import { Head, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Card from '@/Components/Ui/Card.vue';
import Button from '@/Components/Ui/Button.vue';
import Input from '@/Components/Ui/Input.vue';
import { ref } from 'vue';

const props = defineProps({
  club: Object,
  officers: { type: Array, default: () => [] },
});

const form = useForm({ office: '', name: '', rank: '' });
const editingId = ref(null);
const edit = useForm({ office: '', name: '', rank: '' });

const add = () => form.post(route('admin.provincial-officers.store', props.club.slug), { preserveScroll: true, onSuccess: () => form.reset() });

const startEdit = (officer) => {
  editingId.value = officer.id;
  edit.office = officer.office;
  edit.name = officer.name;
  edit.rank = officer.rank ?? '';
};

const save = (officer) => edit.put(route('admin.provincial-officers.update', [props.club.slug, officer.id]), { preserveScroll: true, onSuccess: () => (editingId.value = null) });

const remove = (officer) => {
  if (confirm(`Remove ${officer.name}?`)) {
    router.delete(route('admin.provincial-officers.destroy', [props.club.slug, officer.id]), { preserveScroll: true });
  }
};

const move = (index, step) => {
  const ids = props.officers.map((o) => o.id);
  [ids[index], ids[index + step]] = [ids[index + step], ids[index]];
  router.put(route('admin.provincial-officers.reorder', props.club.slug), { ids }, { preserveScroll: true });
};
</script>

<template>
  <Head title="Provincial officers" />
  <AdminLayout title="Provincial officers">
    <div class="max-w-3xl mx-auto space-y-4">
      <h1 class="text-2xl font-bold">Provincial officers</h1>
      <p class="text-sm text-slate-600">Shown on the section's public site in this order. The people need not have an account.</p>

      <Card v-for="(officer, index) in officers" :key="officer.id" class="p-4">
        <form v-if="editingId === officer.id" class="grid gap-3 sm:grid-cols-3" @submit.prevent="save(officer)">
          <Input v-model="edit.office" label="Office" :error="edit.errors.office" required />
          <Input v-model="edit.name" label="Name" :error="edit.errors.name" required />
          <Input v-model="edit.rank" label="Rank" :error="edit.errors.rank" />
          <div class="sm:col-span-3 flex gap-2">
            <Button type="submit" :loading="edit.processing">Save</Button>
            <Button variant="secondary" @click="editingId = null">Cancel</Button>
          </div>
        </form>
        <div v-else class="flex flex-wrap items-center justify-between gap-3">
          <div>
            <div class="text-sm text-slate-600">{{ officer.office }}</div>
            <div class="font-semibold">{{ [officer.rank, officer.name].filter(Boolean).join(' ') }}</div>
          </div>
          <div class="flex gap-2">
            <Button variant="secondary" size="sm" :disabled="index === 0" aria-label="Move up" @click="move(index, -1)">Up</Button>
            <Button variant="secondary" size="sm" :disabled="index === officers.length - 1" aria-label="Move down" @click="move(index, 1)">Down</Button>
            <Button variant="secondary" size="sm" @click="startEdit(officer)">Edit</Button>
            <Button variant="danger" size="sm" @click="remove(officer)">Remove</Button>
          </div>
        </div>
      </Card>

      <Card class="p-4">
        <form class="grid gap-3 sm:grid-cols-3" @submit.prevent="add">
          <Input v-model="form.office" label="Office" :error="form.errors.office" required />
          <Input v-model="form.name" label="Name" :error="form.errors.name" required />
          <Input v-model="form.rank" label="Rank" :error="form.errors.rank" />
          <div class="sm:col-span-3"><Button type="submit" :loading="form.processing">Add officer</Button></div>
        </form>
      </Card>
    </div>
  </AdminLayout>
</template>
