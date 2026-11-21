<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Card from '@/Components/Ui/Card.vue';
import Button from '@/Components/Ui/Button.vue';
import Input from '@/Components/Ui/Input.vue';
import Select from '@/Components/Ui/Select.vue';

const props = defineProps({
  club: Object,
  hall: Object,
  kinds: Object,
  photos: { type: Array, default: () => [] },
});

const form = useForm({
  name: props.hall.name,
  kind: props.hall.kind,
  address_line_1: props.hall.address_line_1 ?? '',
  address_line_2: props.hall.address_line_2 ?? '',
  town: props.hall.town ?? '',
  county: props.hall.county ?? '',
  postcode: props.hall.postcode ?? '',
  country: props.hall.country ?? '',
  telephone: props.hall.telephone ?? '',
  email: props.hall.email ?? '',
  website_url: props.hall.website_url ?? '',
});

const kindOptions = Object.entries(props.kinds).map(([value, label]) => ({ value, label }));

const save = () => form.put(route('admin.hall.update', { clubSlug: props.club.slug }), { preserveScroll: true });

const photoForm = useForm({ photo: null });
const upload = (event) => {
  photoForm.photo = event.target.files[0] ?? null;
  if (!photoForm.photo) return;
  photoForm.post(route('admin.hall.photos.store', { clubSlug: props.club.slug }), {
    preserveScroll: true,
    onFinish: () => { photoForm.reset(); event.target.value = ''; },
  });
};
const removePhoto = (photo) => {
  if (confirm('Remove this photo?')) {
    useForm({}).delete(route('admin.hall.photos.destroy', { clubSlug: props.club.slug, mediaId: photo.id }), { preserveScroll: true });
  }
};
</script>

<template>
  <Head title="Hall details" />
  <AdminLayout title="Hall details">
    <div class="max-w-3xl space-y-6">
      <div>
        <p class="text-sm text-slate-600 dark:text-slate-400">
          What visitors see on the lodge directory.
          Changes are live as soon as you save.
          <a :href="`/lodges/halls/${hall.slug}`" target="_blank" rel="noopener" class="text-blue-600 hover:underline">View the public page</a>
        </p>
      </div>

      <Card>
        <form class="space-y-4 p-5" @submit.prevent="save">
          <Input v-model="form.name" label="Name" required :error="form.errors.name" />
          <Select v-model="form.kind" label="Kind of place" :options="kindOptions" :error="form.errors.kind" />
          <Input v-model="form.address_line_1" label="Address line 1" :error="form.errors.address_line_1" />
          <Input v-model="form.address_line_2" label="Address line 2" :error="form.errors.address_line_2" />
          <div class="grid gap-4 sm:grid-cols-2">
            <Input v-model="form.town" label="Town" :error="form.errors.town" />
            <Input v-model="form.county" label="County" :error="form.errors.county" />
            <Input v-model="form.postcode" label="Postcode" :error="form.errors.postcode" />
            <Input v-model="form.country" label="Country" :error="form.errors.country" />
            <Input v-model="form.telephone" label="Telephone" :error="form.errors.telephone" />
            <Input v-model="form.email" type="email" label="Email" :error="form.errors.email" />
          </div>
          <Input v-model="form.website_url" label="Website" placeholder="https://" :error="form.errors.website_url" />
          <Button type="submit" :disabled="form.processing">Save details</Button>
        </form>
      </Card>

      <Card>
        <div class="space-y-4 p-5">
          <h2 class="text-lg font-semibold">Photos</h2>
          <p v-if="!photos.length" class="text-sm text-slate-500">No photos yet.</p>
          <div v-else class="grid grid-cols-2 gap-3 sm:grid-cols-3">
            <div v-for="photo in photos" :key="photo.id" class="space-y-1">
              <img :src="photo.url" :alt="photo.name" class="aspect-[4/3] w-full rounded-lg object-cover" />
              <button type="button" class="text-xs text-rose-600 dark:text-rose-400 hover:underline" @click="removePhoto(photo)">Remove</button>
            </div>
          </div>
          <div>
            <label class="block text-sm font-medium" for="hall-photo">Add a photo</label>
            <input id="hall-photo" type="file" accept="image/jpeg,image/png,image/webp" class="mt-1 text-sm" :disabled="photoForm.processing" @change="upload" />
            <p v-if="photoForm.errors.photo" class="mt-1 text-sm text-rose-600 dark:text-rose-400">{{ photoForm.errors.photo }}</p>
          </div>
        </div>
      </Card>
    </div>
  </AdminLayout>
</template>
