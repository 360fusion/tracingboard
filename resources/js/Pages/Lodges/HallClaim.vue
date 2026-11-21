<script setup>
import { Link, router, useForm } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';

const props = defineProps({
    hall: { type: Object, required: true },
    roles: { type: Object, required: true },
    claim: { type: Object, default: null },
});

const form = useForm({ claimant_role: '', message: '', phone: '', confirm: false });
const replyForm = useForm({ reply: '' });

function submit() {
    form.post(route('lodges.hall.claim.store', props.hall.slug));
}

function respond() {
    replyForm.post(route('lodges.hall.claim.respond', { slug: props.hall.slug, id: props.claim.id }));
}

function withdraw() {
    router.delete(route('lodges.hall.claim.withdraw', { slug: props.hall.slug, id: props.claim.id }));
}

const inputClass = 'w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-900';
</script>

<template>
    <PublicLayout :title="`Run ${hall.name}`">
        <div class="mx-auto max-w-2xl space-y-6">
            <div>
                <Link :href="route('lodges.hall', hall.slug)" class="text-sm text-blue-600 hover:underline dark:text-blue-400">← {{ hall.name }}</Link>
                <h1 class="mt-2 text-2xl font-semibold tracking-tight">Run {{ hall.name }}</h1>
                <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">{{ hall.town }}<span v-if="hall.province"> · {{ hall.province }}</span></p>
            </div>

            <section v-if="claim" class="space-y-4 rounded-xl border border-slate-200 bg-white p-4 text-sm sm:p-5 dark:border-slate-800 dark:bg-slate-900">
                <p v-if="claim.status === 'pending'">Your claim is being checked. We will email you when it has been decided.</p>
                <template v-else>
                    <p>We have a question about your claim:</p>
                    <p class="whitespace-pre-wrap rounded-lg bg-slate-100 p-3 dark:bg-slate-800/60">{{ claim.question }}</p>
                    <form class="space-y-3" @submit.prevent="respond">
                        <label class="block font-medium" for="reply">Your reply</label>
                        <textarea id="reply" v-model="replyForm.reply" rows="4" required :class="inputClass" />
                        <p v-if="replyForm.errors.reply || replyForm.errors.claim" class="text-xs text-rose-600">{{ replyForm.errors.reply || replyForm.errors.claim }}</p>
                        <button type="submit" :disabled="replyForm.processing" class="rounded-xl bg-blue-600 px-4 py-2 font-medium text-white hover:bg-blue-500 disabled:opacity-60">Send my reply</button>
                    </form>
                </template>
                <button type="button" class="text-blue-600 hover:underline dark:text-blue-400" @click="withdraw">Withdraw my claim</button>
            </section>

            <template v-else>
                <p class="rounded-lg bg-slate-100 p-4 text-sm text-slate-700 dark:bg-slate-800/60 dark:text-slate-300">
                    Running a hall here lets its board keep the hall's details, events and bookings in one place. Because it gives access to a hall's records, we check every
                    request before it is approved, and we may ask you for more. Only ask to run a hall you have a part in.
                </p>

                <form class="space-y-4 rounded-xl border border-slate-200 bg-white p-4 sm:p-5 dark:border-slate-800 dark:bg-slate-900" @submit.prevent="submit">
                    <p v-if="form.errors.hall" role="alert" class="rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700 dark:bg-rose-950/50 dark:text-rose-300">{{ form.errors.hall }}</p>

                    <div>
                        <label class="mb-1 block text-sm font-medium" for="role">Your part in the hall</label>
                        <select id="role" v-model="form.claimant_role" required :class="inputClass">
                            <option value="" disabled>Choose one</option>
                            <option v-for="(label, key) in roles" :key="key" :value="key">{{ label }}</option>
                        </select>
                        <p v-if="form.errors.claimant_role" class="mt-1 text-xs text-rose-600">{{ form.errors.claimant_role }}</p>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium" for="message">Why you are asking <span class="text-rose-600 dark:text-rose-400" aria-hidden="true">*</span></label>
                        <textarea id="message" v-model="form.message" rows="4" required :class="inputClass" placeholder="For example: I am the hall secretary and would like to keep our details and events up to date here." />
                        <p v-if="form.errors.message" class="mt-1 text-xs text-rose-600">{{ form.errors.message }}</p>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium" for="phone">A phone number we can reach you on (optional)</label>
                        <input id="phone" v-model="form.phone" type="tel" :class="inputClass" />
                        <p v-if="form.errors.phone" class="mt-1 text-xs text-rose-600">{{ form.errors.phone }}</p>
                    </div>

                    <label class="flex items-start gap-2 text-sm">
                        <input v-model="form.confirm" type="checkbox" class="mt-1" />
                        <span>I have a part in running this hall and I am allowed to ask to manage it.</span>
                    </label>
                    <p v-if="form.errors.confirm" class="text-xs text-rose-600">Please tick to confirm.</p>

                    <button type="submit" :disabled="form.processing" class="rounded-xl bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-500 disabled:opacity-60">Send my claim</button>
                </form>
            </template>
        </div>
    </PublicLayout>
</template>
