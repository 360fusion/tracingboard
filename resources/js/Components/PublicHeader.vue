<script setup>
// The signed-out header shared by the homepage, the contact page and the lodge directory.
import { Link } from '@inertiajs/vue3';
import Button from '@/Components/Ui/Button.vue';
</script>

<template>
    <header class="sticky top-0 z-50 border-b border-slate-200/80 bg-slate-50/90 backdrop-blur dark:border-slate-800/80 dark:bg-slate-950/90">
        <div class="mx-auto flex h-16 max-w-6xl items-center justify-between gap-3 px-4 sm:px-6">
            <Link :href="route('home')" class="flex items-center gap-2.5 font-display text-xl font-bold tracking-tight text-slate-900 dark:text-white">
                <img src="/icons/tracing-board-mark.svg" alt="" class="h-8 w-8">Tracing Board
            </Link>
            <nav aria-label="Main" class="flex items-center gap-1 text-sm font-medium sm:gap-2">
                <!-- A plain link, so on the homepage it only scrolls. -->
                <a :href="`${route('home')}#features`" class="hidden whitespace-nowrap rounded-xl px-3 py-2 text-slate-600 hover:text-slate-900 sm:inline-block dark:text-slate-300 dark:hover:text-white">What it does</a>
                <Link :href="route('contact')" class="hidden whitespace-nowrap rounded-xl px-3 py-2 text-slate-600 hover:text-slate-900 sm:inline-block dark:text-slate-300 dark:hover:text-white">Contact</Link>
                <Button size="sm" :href="route('login')">Sign in</Button>
            </nav>
        </div>
    </header>
</template>
