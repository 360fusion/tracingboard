<script setup>
import { computed, ref } from 'vue';
import SuperAdminLayout from '@/Layouts/SuperAdminLayout.vue';
import PageHeader from '@/Components/Ui/PageHeader.vue';
import Card from '@/Components/Ui/Card.vue';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Input from '@/Components/Ui/Input.vue';
import Modal from '@/Components/Ui/Modal.vue';
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
    club: { type: Object, required: true },
    people: { type: Array, default: () => [] },
    // Set for a directory listing that has no account yet.
    lodgeId: { type: Number, default: null },
});

const setUpOpen = ref(false);
const setUpForm = useForm({ name: '', email: '' });

function submitSetUp() {
    setUpForm.post(route('superadmin.lodges.account', props.lodgeId), { onSuccess: () => { setUpOpen.value = false; } });
}

const SIGN_IN = {
    active: ['success', 'Signed in'],
    invited: ['warning', 'Invited'],
    expired: ['danger', 'Invite expired'],
    waiting: ['info', 'Waiting for approval'],
    left: ['neutral', 'Access ended'],
    none: ['neutral', 'No account'],
};

const details = computed(() => [
    ['Order or type', props.club.type],
    ['Number', props.club.number],
    ['Province', props.club.province],
    ['Hall', props.club.hall],
    ['Town', props.club.town],
    ['Email', props.club.email],
    ['Address', props.club.slug && `/${props.club.slug}`],
    ['Meets', props.club.meets],
    ['Website', props.club.website],
    ['Plan', props.club.plan],
    ['Status', props.club.status],
    ['Created', props.club.created],
].filter(([, value]) => value));

const search = ref('');
const shown = computed(() => {
    const q = search.value.trim().toLowerCase();

    return q ? props.people.filter((p) => `${p.name} ${p.email ?? ''} ${p.office ?? ''}`.toLowerCase().includes(q)) : props.people;
});
const signedIn = computed(() => props.people.filter((p) => p.sign_in === 'active').length);
</script>

<template>
    <SuperAdminLayout :title="club.name">
        <div class="space-y-6">
            <PageHeader :title="club.name" :description="lodgeId ? 'In the directory, with no account yet.' : `${people.length} on the roster or able to sign in, ${signedIn} signed in.`">
                <template #actions>
                    <Button v-if="lodgeId" @click="setUpOpen = true">Set up account</Button>
                    <template v-else>
                        <Button :href="route('admin.analytics', club.slug)">Open its admin</Button>
                        <Button variant="secondary" :href="`/site/${club.slug}`" external>Public website</Button>
                    </template>
                    <Button v-if="club.listing_slug" variant="secondary" :href="route('lodges.show', club.listing_slug)" external>Directory listing</Button>
                </template>
            </PageHeader>

            <Card>
                <dl class="grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
                    <div v-for="[label, value] in details" :key="label">
                        <dt class="text-xs text-slate-500 dark:text-slate-400">{{ label }}</dt>
                        <dd class="font-medium text-slate-900 dark:text-white">{{ value }}</dd>
                    </div>
                </dl>
            </Card>

            <Card v-if="!lodgeId" padding="none" class="overflow-hidden">
                <div class="flex flex-wrap items-end justify-between gap-3 border-b border-slate-200 p-4 dark:border-slate-800">
                    <h2 class="text-base font-semibold text-slate-900 dark:text-white">Members</h2>
                    <Input v-model="search" placeholder="Search name, email or office" aria-label="Search members" class="min-w-64" />
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-slate-50 border-b border-slate-200 text-slate-500 font-semibold uppercase tracking-wider dark:bg-slate-950 dark:border-slate-800 dark:text-slate-400">
                            <tr>
                                <th class="py-3 px-4">Name</th>
                                <th class="py-3 px-4">Office</th>
                                <th class="py-3 px-4">Membership</th>
                                <th class="py-3 px-4">Access</th>
                                <th class="py-3 px-4">Sign-in</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200 text-slate-700 dark:divide-slate-800 dark:text-slate-300">
                            <tr v-for="(p, i) in shown" :key="i" class="hover:bg-slate-50 dark:hover:bg-slate-800/40">
                                <td class="py-3 px-4">
                                    <div class="font-medium text-slate-900 dark:text-white">{{ p.name }}</div>
                                    <div class="text-slate-500 dark:text-slate-400">{{ p.email }}</div>
                                </td>
                                <td class="py-3 px-4">{{ p.office }}</td>
                                <td class="py-3 px-4">{{ p.membership }}</td>
                                <td class="py-3 px-4">{{ p.role }}</td>
                                <td class="py-3 px-4"><Badge :variant="SIGN_IN[p.sign_in][0]">{{ SIGN_IN[p.sign_in][1] }}</Badge></td>
                            </tr>
                            <tr v-if="shown.length === 0">
                                <td colspan="5" class="py-12 px-4 text-center text-sm text-slate-500 dark:text-slate-400">Nobody matches.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </Card>
        </div>

        <Modal :open="setUpOpen" :title="`Set up account for ${club.name}`" @close="setUpOpen = false">
            <form class="space-y-3" @submit.prevent="submitSetUp">
                <p class="text-sm text-slate-600 dark:text-slate-400">The account is made from this listing and the person is invited by email as its owner.</p>
                <Input v-model="setUpForm.name" label="Their name" required :error="setUpForm.errors.name" />
                <Input v-model="setUpForm.email" type="email" label="Their email" required :error="setUpForm.errors.email" />
                <div class="flex justify-end gap-2">
                    <Button variant="secondary" @click="setUpOpen = false">Cancel</Button>
                    <Button type="submit" :loading="setUpForm.processing">Send invitation</Button>
                </div>
            </form>
        </Modal>
    </SuperAdminLayout>
</template>
