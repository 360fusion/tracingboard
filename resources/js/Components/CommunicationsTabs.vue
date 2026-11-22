<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import Tabs from '@/Components/Ui/Tabs.vue';

const props = defineProps({
  club: Object,
  activeTab: {
    type: String,
    required: true, // 'posts', 'newsletters', 'updates'
  },
});

const page = usePage();

const items = computed(() => [
  { key: 'posts', label: 'News articles & posts', href: route('admin.posts.index', { clubSlug: props.club.slug }) },
  // The newsletters route is not served for a province group.
  ...(page.props.clubIsProvinceGroup ? [] : [{ key: 'newsletters', label: 'Newsletters & broadcasts', href: route('admin.newsletters.index', { clubSlug: props.club.slug }) }]),
  { key: 'updates', label: 'Updates & weekly digest', href: route('admin.updates.index', { clubSlug: props.club.slug }) },
]);
</script>

<template>
  <Tabs :items="items" :active="activeTab" label="Communications" class="mb-6" />
</template>
