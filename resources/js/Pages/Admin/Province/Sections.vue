<script setup>
import { ref } from 'vue';
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
  orders: { type: Array, default: () => [] },
  sections: { type: Array, default: () => [] },
});

const add = useForm({ order_club_type_id: '', name: '' });
function addSection() {
  add.post(route('admin.sections.store', { clubSlug: props.club.slug }), { preserveScroll: true, onSuccess: () => add.reset() });
}
function orderOptions() {
  return [{ value: '', label: 'Choose an order' }, ...props.orders.map(o => ({ value: o.id, label: o.name }))];
}

const appointing = ref(null);
const appoint = useForm({ name: '', email: '' });
function startAppoint(section) {
  appointing.value = section.id;
  appoint.reset();
}
function sendAppoint(section) {
  appoint.post(route('admin.sections.admins.store', { clubSlug: props.club.slug, sectionId: section.id }), {
    preserveScroll: true,
    onSuccess: () => (appointing.value = null),
  });
}

const removing = ref(null);
function confirmRemove() {
  router.delete(route('admin.sections.destroy', { clubSlug: props.club.slug, sectionId: removing.value.id }), {
    preserveScroll: true,
    onFinish: () => (removing.value = null),
  });
}
</script>

<template>
  <Head title="Sections" />
  <AdminLayout title="Sections">
    <div class="max-w-3xl mx-auto space-y-4">

      <Card v-for="section in sections" :key="section.id" class="p-5">
        <div class="flex items-center justify-between gap-3">
          <div>
            <div class="font-semibold">{{ section.name }} <span class="text-sm font-normal text-slate-600">{{ section.order }}</span></div>
            <div class="text-sm text-slate-600">
              <template v-if="section.admins.length">
                <span v-for="(a, i) in section.admins" :key="i">{{ i ? ', ' : '' }}{{ a.name }} <Badge v-if="a.pending">Invited</Badge></span>
              </template>
              <template v-else>No admin yet</template>
            </div>
          </div>
          <div class="flex gap-2">
            <a :href="`/site/${section.slug}`" class="inline-flex min-h-11 items-center px-3 text-sm font-semibold text-blue-700">View site</a>
            <Button variant="secondary" size="sm" @click="startAppoint(section)">Appoint admin</Button>
            <Button variant="danger" size="sm" @click="removing = section">Remove</Button>
          </div>
        </div>
        <form v-if="appointing === section.id" class="mt-4 grid gap-3 sm:grid-cols-2" @submit.prevent="sendAppoint(section)">
          <Input v-model="appoint.name" label="Name" :error="appoint.errors.name" required />
          <Input v-model="appoint.email" type="email" label="Email" :error="appoint.errors.email" required />
          <div class="sm:col-span-2 flex gap-2">
            <Button type="submit" :loading="appoint.processing">Send invitation</Button>
            <Button variant="secondary" @click="appointing = null">Cancel</Button>
          </div>
        </form>
      </Card>

      <Card class="p-5">
        <h2 class="font-semibold mb-3">Add a section</h2>
        <p v-if="!orders.length" class="text-sm text-slate-600">Every order already has a section.</p>
        <form v-else class="grid gap-3 sm:grid-cols-2" @submit.prevent="addSection">
          <Select v-model="add.order_club_type_id" label="Order" :options="orderOptions()" :error="add.errors.order_club_type_id" required />
          <Input v-model="add.name" label="Name" :error="add.errors.name" required />
          <div class="sm:col-span-2"><Button type="submit" :loading="add.processing">Add section</Button></div>
        </form>
      </Card>
    </div>

    <ConfirmDialog :open="!!removing" title="Remove section" confirm-label="Remove" @close="removing = null" @confirm="confirmRemove">
      {{ removing?.name }} will no longer be part of {{ club.name }}. Its records are kept.
    </ConfirmDialog>
  </AdminLayout>
</template>
