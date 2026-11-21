<script setup>
import { computed } from 'vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import Alert from '@/Components/Ui/Alert.vue';
import Button from '@/Components/Ui/Button.vue';
import Input from '@/Components/Ui/Input.vue';
import Select from '@/Components/Ui/Select.vue';
import Textarea from '@/Components/Ui/Textarea.vue';
import LevelRule from '@/Components/Ui/LevelRule.vue';
import UiIcon from '@/Components/Ui/UiIcon.vue';
import PublicHeader from '@/Components/PublicHeader.vue';
import PublicFooter from '@/Components/PublicFooter.vue';

const props = defineProps({
    topics: { type: Array, required: true },
    topic: { type: String, default: 'access' },
    startedAt: { type: String, required: true },
});

const page = usePage();
const sent = computed(() => page.props.flash?.success ?? '');

const form = useForm({
    name: '',
    email: '',
    lodge: '',
    topic: props.topic,
    message: '',
    // Left empty by people; ContactController drops a message that fills it in or arrives too quickly.
    website: '',
    started_at: props.startedAt,
});

function submit() {
    form.post(route('contact.store'), { preserveScroll: true, onSuccess: () => form.reset('name', 'email', 'lodge', 'message') });
}

const steps = [
    { icon: 'mail', text: 'Tell us about your lodge and your office in it.' },
    { icon: 'shield', text: 'We check who you are with your lodge or province before giving access.' },
    { icon: 'user-add', text: 'You set up your lodge and invite your members.' },
];
</script>

<template>
    <Head title="Contact us">
        <meta name="description" content="Ask about Tracing Board for your lodge or chapter. It is invitation-only for now: tell us about your lodge and we will be in touch." />
    </Head>

    <div class="tb-public min-h-dvh bg-slate-50 text-slate-800 dark:bg-slate-950 dark:text-slate-200">
        <PublicHeader />

        <main id="main-content" tabindex="-1" class="mx-auto grid max-w-6xl gap-12 px-4 py-12 focus:outline-none sm:px-6 sm:py-20 lg:grid-cols-[1fr_1.2fr]">
            <div>
                <p class="text-xs font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">Invitation only, for now</p>
                <h1 class="mt-4 font-display text-4xl font-bold leading-tight tracking-tight text-slate-900 sm:text-5xl dark:text-white">Bring Tracing Board to your lodge</h1>
                <div class="mt-6"><LevelRule /></div>
                <p class="mt-6 text-lg leading-relaxed text-slate-600 dark:text-slate-300">
                    We are opening Tracing Board one lodge at a time. Tell us about yours and we will be in touch. You can also ask us anything else here.
                </p>
                <ol class="mt-8 space-y-4">
                    <li v-for="step in steps" :key="step.text" class="flex gap-3">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-navy-900 text-gold-300 dark:bg-navy-800"><UiIcon :name="step.icon" /></span>
                        <span class="pt-1.5 text-slate-700 dark:text-slate-200">{{ step.text }}</span>
                    </li>
                </ol>
                <p class="mt-8 text-sm text-slate-500 dark:text-slate-400">Already a member of a lodge that uses Tracing Board? Ask your Secretary for an invitation, or sign in.</p>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-xl shadow-slate-900/5 sm:p-8 dark:border-slate-800 dark:bg-slate-900">
                <Alert v-if="sent" variant="success" role="status">{{ sent }}</Alert>
                <form v-else class="space-y-5" @submit.prevent="submit">
                    <div class="grid gap-5 sm:grid-cols-2">
                        <Input v-model="form.name" label="Your name" autocomplete="name" required :error="form.errors.name" />
                        <Input v-model="form.email" type="email" label="Email" autocomplete="email" required :error="form.errors.email" />
                    </div>
                    <Input v-model="form.lodge" label="Your lodge or chapter" hint="Name and number, if this is about a lodge" :error="form.errors.lodge" />
                    <Select v-model="form.topic" :options="topics" label="What it is about" required :error="form.errors.topic" />
                    <Textarea v-model="form.message" label="Your message" :rows="6" required placeholder="For example: I'm Secretary of Lodge of Harmony No. 1234 and we'd like to use it for summonses and dining." :error="form.errors.message" />
                    <div class="hidden" aria-hidden="true">
                        <label>Leave this empty <input v-model="form.website" type="text" name="website" tabindex="-1" autocomplete="off"></label>
                    </div>
                    <p class="text-xs text-slate-500 dark:text-slate-400">We only use these details to reply to you.</p>
                    <Button type="submit" size="lg" :loading="form.processing" block>Send message</Button>
                </form>
            </div>
        </main>

        <PublicFooter />
    </div>
</template>
