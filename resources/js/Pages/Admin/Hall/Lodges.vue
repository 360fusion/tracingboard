<script setup>
import { Head } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Card from '@/Components/Ui/Card.vue';

defineProps({
  club: Object,
  lodges: { type: Array, default: () => [] },
});

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

function pattern(s) {
  const months = (s.months ?? []).map(Number);
  const when = months.length === 12 ? 'every month' : months.map(m => MONTHS[m - 1]).join(', ');
  return `${s.occurrence} ${s.day_of_week}, ${when}${s.start_time ? ' at ' + s.start_time.slice(0, 5) : ''}`;
}
</script>

<template>
  <Head title="Lodges" />
  <AdminLayout>
    <div class="max-w-3xl mx-auto space-y-4">
      <h1 class="text-2xl font-bold">Lodges that meet here</h1>
      <Card v-if="!lodges.length" class="p-6 text-slate-500">No lodges or chapters are listed at this hall yet.</Card>
      <Card v-for="lodge in lodges" :key="lodge.name + lodge.number" class="p-5">
        <div class="font-semibold">{{ lodge.name }}<span v-if="lodge.number"> No. {{ lodge.number }}</span></div>
        <ul v-if="lodge.schedules.length" class="mt-1 text-sm text-slate-600">
          <li v-for="(s, i) in lodge.schedules" :key="i">{{ pattern(s) }}</li>
        </ul>
        <p v-else-if="lodge.meets_text" class="mt-1 text-sm text-slate-600">{{ lodge.meets_text }}</p>
      </Card>
    </div>
  </AdminLayout>
</template>
