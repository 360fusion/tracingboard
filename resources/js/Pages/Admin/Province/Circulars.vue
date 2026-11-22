<script setup>
// A province section's circulars: each goes to its chapters' Secretaries, who pass it on to their own members.
import { Head, useForm } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Card from '@/Components/Ui/Card.vue';
import Button from '@/Components/Ui/Button.vue';
import Input from '@/Components/Ui/Input.vue';
import Textarea from '@/Components/Ui/Textarea.vue';

const props = defineProps({
  club: Object,
  circulars: { type: Array, default: () => [] },
});

const form = useForm({ title: '', body: '', attachment: null });

const pickFile = (event) => {
  form.attachment = event.target.files[0] ?? null;
};

const send = () => form.post(route('admin.circulars.store', props.club.slug), {
  forceFormData: true,
  preserveScroll: true,
  onSuccess: () => form.reset(),
});
</script>

<template>
  <Head title="Circulars" />
  <AdminLayout title="Circulars">
    <div class="max-w-3xl mx-auto space-y-4">
      <h1 class="text-2xl font-bold">Circulars</h1>
      <p class="text-sm text-slate-600">A circular goes to each chapter's Secretary, who passes it on to their members. Members are not emailed by the section.</p>

      <Card class="p-4">
        <form class="space-y-3" @submit.prevent="send">
          <Input v-model="form.title" label="Title" :error="form.errors.title" required />
          <Textarea v-model="form.body" label="Text" hint="Write the circular, or add a note to go with the PDF." :rows="6" :error="form.errors.body" />
          <div>
            <label for="circular-pdf" class="block text-sm font-medium text-slate-700 dark:text-slate-200">The PDF</label>
            <input id="circular-pdf" type="file" accept="application/pdf,.pdf" class="mt-1 block w-full text-sm text-slate-700 dark:text-slate-200" @change="pickFile" />
            <p v-if="form.errors.attachment" class="mt-1 text-sm text-rose-600 dark:text-rose-400">{{ form.errors.attachment }}</p>
          </div>
          <Button type="submit" :loading="form.processing">Send to the chapters</Button>
        </form>
      </Card>

      <Card v-if="!circulars.length" class="p-6 text-slate-500">No circulars sent yet.</Card>
      <Card v-for="circular in circulars" :key="circular.id" class="p-4">
        <div class="font-semibold">{{ circular.title }}</div>
        <p class="mt-1 text-sm text-slate-600">{{ circular.sent_on }} · to {{ circular.chapters }} {{ circular.chapters === 1 ? 'chapter' : 'chapters' }}<span v-if="circular.attachment_name"> · {{ circular.attachment_name }}</span></p>
      </Card>
    </div>
  </AdminLayout>
</template>
