<script setup>
// What a list shows before it has anything in it: the Tracing Board mark, faint, a short title, a line on what will appear
// and how to add the first one (the default slot, so it can hold a link), and room for buttons. The caller supplies the
// card around it. Filters that match nothing are not an empty state: say so in a plain line instead.
defineProps({
    title: { type: String, required: true },
});
</script>

<template>
    <div class="flex flex-col items-center gap-2 px-4 py-6 text-center">
        <img src="/icons/tracing-board-mark-navy.svg" alt="" class="h-10 w-10 opacity-30 dark:hidden">
        <img src="/icons/tracing-board-mark-gold.svg" alt="" class="hidden h-10 w-10 opacity-40 dark:block">
        <h3 class="text-base font-semibold text-slate-900 dark:text-white">{{ title }}</h3>
        <p v-if="$slots.default" class="max-w-md text-sm text-slate-500 dark:text-slate-400"><slot /></p>
        <div v-if="$slots.actions" class="mt-2 flex flex-wrap items-center justify-center gap-2">
            <slot name="actions" />
        </div>
    </div>
</template>
