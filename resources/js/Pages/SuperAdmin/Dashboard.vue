<script setup>
import { ref, watch } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import SuperAdminLayout from '@/Layouts/SuperAdminLayout.vue';
import PageHeader from '@/Components/Ui/PageHeader.vue';
import Card from '@/Components/Ui/Card.vue';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import StatCard from '@/Components/Ui/StatCard.vue';
import UiIcon from '@/Components/Ui/UiIcon.vue';
import Modal from '@/Components/Ui/Modal.vue';
import Input from '@/Components/Ui/Input.vue';
import Select from '@/Components/Ui/Select.vue';

defineProps({
  metrics: Object,
  clubsByType: Array,
  clubs: Array,
  setUpTypes: { type: Array, default: () => [] },
});

const STATUS = { invited: ['warning', 'Invited'], expired: ['danger', 'Invite expired'], active: ['success', 'Active'] };

// Set up account: choose the kind, find its directory listing, then invite the person who will run it.
const setUpOpen = ref(false);
const setUpType = ref('hall');
const listingQuery = ref('');
const listings = ref([]);
const listing = ref(null);
const setUpForm = useForm({ name: '', email: '' });
let searchTimer = null;

function openSetUp() {
  setUpOpen.value = true;
  listing.value = null;
  listingQuery.value = '';
  listings.value = [];
  setUpForm.reset();
  setUpForm.clearErrors();
}

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
  <SuperAdminLayout title="System analytics">
    <div class="space-y-8">
      <PageHeader title="System analytics and metrics" description="An overview of registered clubs, orders, user accounts and platform volume.">
        <template #actions>
          <Button @click="openSetUp">
            <template #icon><UiIcon name="user-add" /></template>
            Set up account
          </Button>
          <Button variant="secondary" :href="route('superadmin.invitations.index')">Invitations</Button>
          <Button variant="secondary" :href="route('superadmin.club_types.index')">
            <template #icon><UiIcon name="settings" /></template>
            Manage club types and presets
          </Button>
        </template>
      </PageHeader>

      <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4">
        <StatCard label="Registered clubs" :value="metrics?.totalClubsCount || 0" caption="Across all order types" />
        <StatCard label="User accounts" :value="metrics?.totalUsersCount || 0" caption="Brethren, officers and members" />
        <StatCard label="Meeting summonses" :value="metrics?.totalMeetingsCount || 0" caption="Circulars and convocations" />
        <StatCard label="Charity grants disbursed" :value="metrics?.totalGrantsDisbursed || ($cs + '0.00')" caption="Total Relief Chest payouts" />
      </div>

      <Card class="space-y-4">
        <div>
          <h2 class="text-base font-semibold text-slate-900 dark:text-white">Clubs by order or type</h2>
          <p class="text-xs text-slate-500 mt-0.5 dark:text-slate-400">Open an order to see its page, offices, registered clubs and official website.</p>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
          <Link
            v-for="ct in clubsByType"
            :key="ct.id"
            :href="route('superadmin.club_types.show', ct.id)"
            class="p-4 bg-slate-50 rounded-xl border border-slate-200 hover:border-blue-400 hover:bg-white transition-colors group flex items-center justify-between dark:bg-slate-800/60 dark:border-slate-700 dark:hover:bg-slate-800"
          >
            <div>
              <div class="font-semibold text-sm text-slate-900 group-hover:text-blue-700 transition-colors flex items-center gap-1.5 dark:text-white dark:group-hover:text-blue-300">
                <span>{{ ct.name }}</span>
                <span class="text-xs text-blue-600 dark:text-blue-400" aria-hidden="true">→</span>
              </div>
              <div class="text-[11px] text-slate-500 font-mono mt-0.5 dark:text-slate-400">Code: {{ ct.code }}</div>
            </div>
            <Badge variant="info">{{ ct.count }}</Badge>
          </Link>
        </div>
      </Card>

      <Card padding="none" class="overflow-hidden">
        <div class="flex items-center justify-between gap-4 border-b border-slate-200 p-4 sm:p-6 dark:border-slate-800">
          <div>
            <h2 class="text-base font-semibold text-slate-900 dark:text-white">Club directory</h2>
            <p class="text-xs text-slate-500 dark:text-slate-400">All clubs registered on the platform with member counts and order types.</p>
          </div>
          <span class="text-xs font-medium text-slate-500 dark:text-slate-400">{{ clubs.length }} club(s)</span>
        </div>

        <div class="overflow-x-auto">
          <table class="w-full text-left text-xs">
            <thead class="bg-slate-50 border-b border-slate-200 text-slate-500 font-semibold uppercase tracking-wider dark:bg-slate-950 dark:border-slate-800 dark:text-slate-400">
              <tr>
                <th class="py-3 px-4">Club name and no.</th>
                <th class="py-3 px-4">Order or type</th>
                <th class="py-3 px-4">Masonic province</th>
                <th class="py-3 px-4">Owners and admins</th>
                <th class="py-3 px-4 text-center">Active members</th>
                <th class="py-3 px-4">Created</th>
                <th class="py-3 px-4 text-right">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-200 text-slate-700 dark:divide-slate-800 dark:text-slate-300">
              <tr v-for="c in clubs" :key="c.id" class="hover:bg-slate-50 transition-colors dark:hover:bg-slate-800/40">
                <td class="py-3.5 px-4 font-medium text-slate-900 dark:text-white">
                  <Link :href="route('superadmin.accounts.show', c.id)" class="text-blue-600 hover:underline dark:text-blue-400">{{ c.name }}</Link> <span v-if="c.lodge_number !== '—'" class="text-slate-500 font-normal dark:text-slate-400">No. {{ c.lodge_number }}</span>
                </td>
                <td class="py-3.5 px-4"><Badge variant="info">{{ c.club_type }}</Badge></td>
                <td class="py-3.5 px-4"><Badge>{{ c.province }}</Badge></td>
                <td class="py-3.5 px-4">
                  <div v-for="(p, i) in c.people" :key="i" class="flex items-center gap-1.5 py-0.5">
                    <span>{{ p.name }}</span>
                    <span class="text-slate-500 dark:text-slate-400">{{ p.role }}</span>
                    <Badge :variant="STATUS[p.status][0]">{{ STATUS[p.status][1] }}</Badge>
                  </div>
                  <span v-if="!c.people.length" class="text-amber-700 dark:text-amber-400">Nobody yet</span>
                </td>
                <td class="py-3.5 px-4 text-center font-medium text-slate-900 dark:text-white">{{ c.members_count }}</td>
                <td class="py-3.5 px-4 text-slate-500 dark:text-slate-400">{{ c.created_at }}</td>
                <td class="py-3.5 px-4 text-right">
                  <Button variant="secondary" size="sm" :href="route('admin.club_acc.charity.index', c.slug)">View workspace</Button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </Card>
    </div>

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
  </SuperAdminLayout>
</template>
