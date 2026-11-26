<script setup>
import { ref, watch } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Input from '@/Components/Ui/Input.vue';
import Modal from '@/Components/Ui/Modal.vue';
import Select from '@/Components/Ui/Select.vue';

/** The superadmin's "Set up account" pop-up, shared by the home page and the Invitations page. */
defineProps({
  setUpTypes: { type: Array, default: () => [] },
});

const setUpOpen = defineModel('open', { type: Boolean, default: false });

// Set up account: choose the kind, find its directory listing, then invite the person who will run it.
const setUpType = ref('hall');
const listingQuery = ref('');
const listings = ref([]);
const listing = ref(null);
const setUpForm = useForm({ name: '', email: '' });
let searchTimer = null;

watch(setUpOpen, (open) => {
  if (!open) {
    return;
  }

  listing.value = null;
  listingQuery.value = '';
  listings.value = [];
  setUpForm.reset();
  setUpForm.clearErrors();
});

async function searchListings() {
  const params = new URLSearchParams({ type: setUpType.value, q: listingQuery.value });
  const response = await fetch(`${route('superadmin.accounts.search')}?${params}`, { headers: { Accept: 'application/json' } });
  listings.value = response.ok ? await response.json() : [];
}

watch([setUpType, listingQuery], () => {
  listing.value = null;
  clearTimeout(searchTimer);
  searchTimer = setTimeout(searchListings, 250);
});

function submitSetUp() {
  const url = setUpType.value === 'hall'
    ? route('superadmin.masonic_halls.board', listing.value.id)
    : route('superadmin.lodges.account', listing.value.id);

  setUpForm.post(url, { preserveScroll: true, onSuccess: () => { setUpOpen.value = false; } });
}
</script>

<template>
  <Modal :open="setUpOpen" title="Set up account" subtitle="Find the hall or lodge in the directory, then invite the person who will run it." @close="setUpOpen = false">
    <form class="space-y-4" @submit.prevent="submitSetUp">
      <Select v-model="setUpType" label="What is it?" :options="setUpTypes" />
      <div class="space-y-1.5">
        <Input v-model="listingQuery" label="Find it in the directory" placeholder="Name, number or town" />
        <ul v-if="listings.length && !listing" class="max-h-56 overflow-y-auto rounded-md border border-slate-200 divide-y divide-slate-200 text-sm dark:border-slate-700 dark:divide-slate-700">
          <li v-for="l in listings" :key="l.id">
            <button type="button" class="w-full px-3 py-2 text-left hover:bg-slate-50 dark:hover:bg-slate-800 flex items-center justify-between gap-2" @click="listing = l">
              <span>{{ l.label }}</span>
              <Badge v-if="l.has_account" variant="info">Has an account</Badge>
            </button>
          </li>
        </ul>
        <p v-else-if="listingQuery && !listing" class="text-xs text-slate-500 dark:text-slate-400">
          Nothing found. Add it on the <Link :href="setUpType === 'hall' ? route('superadmin.masonic_halls.index') : route('superadmin.lodges.index')" class="text-blue-600 hover:underline dark:text-blue-400">{{ setUpType === 'hall' ? 'Masonic halls' : 'Lodges' }}</Link> page first.
        </p>
        <div v-if="listing" class="flex items-center justify-between gap-2 rounded-md bg-slate-50 px-3 py-2 text-sm dark:bg-slate-800">
          <span class="font-medium text-slate-900 dark:text-white">{{ listing.label }}</span>
          <button type="button" class="text-xs text-blue-600 hover:underline dark:text-blue-400" @click="listing = null">Change</button>
        </div>
        <p v-if="listing?.has_account" class="text-xs text-slate-500 dark:text-slate-400">This already has an account, so the person is added to it as another owner.</p>
      </div>
      <Input v-model="setUpForm.name" label="Their name" required :error="setUpForm.errors.name" />
      <Input v-model="setUpForm.email" type="email" label="Their email" required :error="setUpForm.errors.email" />
      <p class="text-xs text-slate-500 dark:text-slate-400">They get an email inviting them as an owner of the account, with a link to choose their password.</p>
      <div class="flex justify-end gap-2">
        <Button variant="secondary" @click="setUpOpen = false">Cancel</Button>
        <Button type="submit" :disabled="!listing" :loading="setUpForm.processing">Send invitation</Button>
      </div>
    </form>
  </Modal>
</template>
