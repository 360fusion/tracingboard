<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Card from '@/Components/Ui/Card.vue';
import Badge from '@/Components/Ui/Badge.vue';

defineProps({
  club: Object,
  chapters: { type: Array, default: () => [] },
});
</script>

<template>
  <Head title="Chapters" />
  <AdminLayout title="Chapters">
    <div class="max-w-3xl mx-auto space-y-4">
      <h1 class="text-2xl font-bold">Chapters in the province</h1>
      <div class="flex gap-4 text-sm">
        <Link :href="route('admin.chapters.listings.index', club.slug)" class="underline">Directory listings</Link>
        <Link :href="route('admin.chapters.fixes.index', club.slug)" class="underline">Suggested fixes</Link>
      </div>
      <Card v-if="!chapters.length" class="p-6 text-slate-500">No chapters are listed for this order yet.</Card>
      <Card v-for="chapter in chapters" :key="chapter.name + chapter.number" class="p-5">
        <div class="flex items-center gap-2 font-semibold">
          <span>{{ chapter.name }}<span v-if="chapter.number"> No. {{ chapter.number }}</span></span>
          <Badge v-if="chapter.on_platform">On the platform</Badge>
        </div>
        <p v-if="chapter.hall || chapter.town" class="mt-1 text-sm text-slate-600">{{ [chapter.hall, chapter.town].filter(Boolean).join(', ') }}</p>
        <p v-if="chapter.meets" class="mt-1 text-sm text-slate-600">{{ chapter.meets }}</p>
        <a v-if="chapter.site_url" :href="chapter.site_url" class="mt-1 inline-block text-sm underline">Visit site</a>
      </Card>
    </div>
  </AdminLayout>
</template>
