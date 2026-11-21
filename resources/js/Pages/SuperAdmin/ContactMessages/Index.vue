<script setup>
import { Head, router } from '@inertiajs/vue3';
import SuperAdminLayout from '@/Layouts/SuperAdminLayout.vue';
import PageHeader from '@/Components/Ui/PageHeader.vue';
import Tabs from '@/Components/Ui/Tabs.vue';
import Card from '@/Components/Ui/Card.vue';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Pagination from '@/Components/Ui/Pagination.vue';

defineProps({
    messages: { type: Object, required: true },
    status: { type: String, default: 'new' },
    newCount: { type: Number, default: 0 },
});

function markHandled(message) {
    router.post(route('superadmin.contact_messages.handle', { id: message.id }), {}, { preserveScroll: true });
}

function reopen(message) {
    router.post(route('superadmin.contact_messages.reopen', { id: message.id }), {}, { preserveScroll: true });
}
</script>

<template>
    <SuperAdminLayout title="Contact messages">
        <Head title="Contact messages" />

        <div class="space-y-6">
            <PageHeader title="Contact messages" description="Messages from the public contact form, including lodges asking for access. Reply from your own email, then mark each one handled." />

            <Tabs
                label="Messages"
                :active="status"
                :items="[
                    { key: 'new', label: newCount ? `New (${newCount})` : 'New', href: route('superadmin.contact_messages.index') },
                    { key: 'handled', label: 'Handled', href: route('superadmin.contact_messages.index', { status: 'handled' }) },
                ]"
            />

            <Card v-if="!messages.data.length" class="border-dashed text-center">
                <p class="text-sm text-slate-500 dark:text-slate-400">No messages here.</p>
            </Card>

            <ul class="space-y-4">
                <li v-for="message in messages.data" :key="message.id">
                    <Card class="space-y-3">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <p class="font-semibold text-slate-900 dark:text-white">{{ message.name }}</p>
                                <a :href="`mailto:${message.email}`" class="text-sm text-blue-600 hover:underline dark:text-blue-400">{{ message.email }}</a>
                                <p v-if="message.lodge" class="text-sm text-slate-600 dark:text-slate-300">{{ message.lodge }}</p>
                            </div>
                            <div class="flex items-center gap-2">
                                <Badge>{{ message.topic }}</Badge>
                                <span class="text-xs text-slate-500 dark:text-slate-400">{{ message.sent_at }}</span>
                            </div>
                        </div>
                        <p class="whitespace-pre-line text-sm text-slate-700 dark:text-slate-200">{{ message.message }}</p>
                        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 pt-3 dark:border-slate-800">
                            <p v-if="message.handled_at" class="text-xs text-slate-500 dark:text-slate-400">Handled {{ message.handled_at }}<template v-if="message.handled_by"> by {{ message.handled_by }}</template></p>
                            <span v-else />
                            <Button v-if="message.handled_at" size="sm" variant="secondary" @click="reopen(message)">Move back to new</Button>
                            <Button v-else size="sm" @click="markHandled(message)">Mark as handled</Button>
                        </div>
                    </Card>
                </li>
            </ul>

            <Pagination :links="messages.links" />
        </div>
    </SuperAdminLayout>
</template>
