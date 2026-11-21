<script setup>
// The signed-out footer shared by the homepage, the contact page and the lodge directory.
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <footer class="border-t border-slate-200 dark:border-slate-800">
        <div class="mx-auto flex max-w-6xl flex-col gap-6 px-4 py-10 text-sm sm:flex-row sm:items-center sm:justify-between sm:px-6">
            <div class="flex items-center gap-2 font-display text-lg font-bold text-slate-900 dark:text-white">
                <img src="/icons/tracing-board-mark.svg" alt="" class="h-6 w-6">Tracing Board
            </div>
            <nav aria-label="Footer" class="flex flex-wrap gap-x-6 gap-y-2 text-slate-600 dark:text-slate-300">
                <Link :href="route('contact', { topic: 'access' })" class="hover:text-slate-900 dark:hover:text-white">Request access</Link>
                <Link :href="route('contact', { topic: 'question' })" class="hover:text-slate-900 dark:hover:text-white">Contact</Link>
                <Link :href="route('login')" class="hover:text-slate-900 dark:hover:text-white">Sign in</Link>
            </nav>
            <p class="text-slate-500 dark:text-slate-400">tracingboard.app</p>
        </div>
    </footer>
</template>
