<script setup>
import { computed } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import PublicHeader from '@/Components/PublicHeader.vue';
import PublicFooter from '@/Components/PublicFooter.vue';
import MembersLayout from '@/Layouts/MembersLayout.vue';

defineProps({
    title: { type: String, required: true },
    description: { type: String, default: '' },
});

const page = usePage();
const user = computed(() => page.props.auth?.user ?? null);
const flash = computed(() => page.props.flash ?? {});
const followError = computed(() => page.props.errors?.follow ?? null);
</script>

<template>
    <!-- Signed in, the directory sits inside the member area (its menu, and the tab bar in the app) under Directory. -->
    <MembersLayout v-if="user" :title="title" active-tab="directory" own-heading>
        <Head v-if="description"><meta name="description" :content="description" /></Head>
        <slot />
        <p class="pt-4 text-xs text-slate-500 dark:text-slate-400">Meeting times shown as "expected" are worked out from the lodge's published pattern and may change. Always confirm with the lodge before you travel.</p>
    </MembersLayout>

    <template v-else>
    <Head :title="title">
        <meta v-if="description" name="description" :content="description" />
    </Head>

    <a href="#main-content" class="sr-only focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-[200] focus:px-4 focus:py-2 focus:rounded-xl focus:bg-blue-600 focus:text-white focus:font-bold focus:text-sm focus:shadow-lg">
      Skip to main content
    </a>

    <div class="min-h-dvh bg-slate-50 text-slate-800 dark:bg-slate-950 dark:text-slate-100">
        <PublicHeader />

        <main id="main-content" tabindex="-1" class="mx-auto max-w-6xl px-4 py-5 focus:outline-none sm:px-6 sm:py-8">
            <p v-if="flash.success" role="status" class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-800 dark:border-emerald-800/50 dark:bg-emerald-950/50 dark:text-emerald-300">{{ flash.success }}</p>
            <p v-if="flash.error || followError" role="alert" class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-2 text-sm text-rose-800 dark:border-rose-800/50 dark:bg-rose-950/50 dark:text-rose-300">{{ flash.error || followError }}</p>
            <slot />
        </main>

        <p class="mx-auto max-w-6xl px-4 pb-10 text-xs text-slate-500 sm:px-6 dark:text-slate-400">
            Meeting times shown as "expected" are worked out from the lodge's published pattern and may change. Always confirm with the lodge before you travel.
        </p>

        <PublicFooter />
    </div>
    </template>
</template>
