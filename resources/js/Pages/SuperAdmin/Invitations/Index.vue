<script setup>
import { reactive, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import SuperAdminLayout from '@/Layouts/SuperAdminLayout.vue';
import PageHeader from '@/Components/Ui/PageHeader.vue';
import Card from '@/Components/Ui/Card.vue';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Input from '@/Components/Ui/Input.vue';
import Select from '@/Components/Ui/Select.vue';
import Pagination from '@/Components/Ui/Pagination.vue';
import UiIcon from '@/Components/Ui/UiIcon.vue';
import SetUpAccountDialog from '@/Components/SuperAdmin/SetUpAccountDialog.vue';

const props = defineProps({
    people: { type: Object, required: true },
    filters: { type: Object, required: true },
    setUpTypes: { type: Array, default: () => [] },
});

const setUpOpen = ref(false);

const STATUS = { invited: ['warning', 'Invited'], expired: ['danger', 'Invite expired'], active: ['success', 'Active'] };
const STATUS_OPTIONS = [
    { value: '', label: 'Everyone' },
    { value: 'invited', label: 'Not signed in yet' },
    { value: 'active', label: 'Active' },
];

const search = reactive({ ...props.filters });

function applyFilters() {
    router.get(route('superadmin.invitations.index'), search, { preserveState: true, replace: true });
}

function resend(person) {
    router.post(route('superadmin.invitations.resend', [person.club_id, person.user_id]), {}, { preserveScroll: true });
}

function cancel(person) {
    if (window.confirm(`Cancel the invitation to ${person.email}?`)) {
        router.delete(route('superadmin.invitations.cancel', [person.club_id, person.user_id]), { preserveScroll: true });
    }
}
</script>

<template>
    <SuperAdminLayout title="Invitations">
        <div class="space-y-6">
            <PageHeader title="Invitations" description="Everyone who runs an account: who has been invited, who has signed in. Resend an invitation or cancel one that is no longer wanted.">
                <template #actions>
                    <Button @click="setUpOpen = true">
                        <template #icon><UiIcon name="user-add" /></template>
                        Set up account
                    </Button>
                </template>
            </PageHeader>

            <Card padding="none" class="overflow-hidden">
                <form class="flex flex-wrap items-end gap-3 border-b border-slate-200 p-4 dark:border-slate-800" @submit.prevent="applyFilters">
                    <Input v-model="search.q" label="Search" placeholder="Name, email or account" class="min-w-64" />
                    <Select v-model="search.status" label="Status" :options="STATUS_OPTIONS" @update:model-value="applyFilters" />
                    <Button type="submit" variant="secondary">Search</Button>
                </form>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-slate-50 border-b border-slate-200 text-slate-500 font-semibold uppercase tracking-wider dark:bg-slate-950 dark:border-slate-800 dark:text-slate-400">
                            <tr>
                                <th class="py-3 px-4">Person</th>
                                <th class="py-3 px-4">Account</th>
                                <th class="py-3 px-4">Role</th>
                                <th class="py-3 px-4">Status</th>
                                <th class="py-3 px-4">Invited</th>
                                <th class="py-3 px-4 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200 text-slate-700 dark:divide-slate-800 dark:text-slate-300">
                            <tr v-for="p in people.data" :key="`${p.club_id}-${p.user_id}`" class="hover:bg-slate-50 dark:hover:bg-slate-800/40">
                                <td class="py-3 px-4">
                                    <div class="font-medium text-slate-900 dark:text-white">{{ p.name }}</div>
                                    <div class="text-slate-500 dark:text-slate-400">{{ p.email }}</div>
                                </td>
                                <td class="py-3 px-4">
                                    <Link :href="route('superadmin.accounts.show', p.club_id)" class="text-blue-600 hover:underline dark:text-blue-400">{{ p.club }}</Link>
                                </td>
                                <td class="py-3 px-4">{{ p.role }}</td>
                                <td class="py-3 px-4"><Badge :variant="STATUS[p.status][0]">{{ STATUS[p.status][1] }}</Badge></td>
                                <td class="py-3 px-4 text-slate-500 dark:text-slate-400">
                                    <template v-if="p.invited_at">{{ p.invited_at }}<span v-if="p.invited_by"> by {{ p.invited_by }}</span></template>
                                </td>
                                <td class="py-3 px-4 text-right whitespace-nowrap">
                                    <div v-if="p.status !== 'active'" class="flex justify-end gap-2">
                                        <Button variant="secondary" size="sm" @click="resend(p)">Resend</Button>
                                        <Button variant="secondary" size="sm" @click="cancel(p)">Cancel</Button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="people.data.length === 0">
                                <td colspan="6" class="py-12 px-4 text-center text-sm text-slate-500 dark:text-slate-400">Nobody matches.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-if="people.links.length > 3" class="border-t border-slate-200 p-3 dark:border-slate-800">
                    <Pagination :links="people.links" />
                </div>
            </Card>
        </div>

        <SetUpAccountDialog v-model:open="setUpOpen" :set-up-types="setUpTypes" />
    </SuperAdminLayout>
</template>
