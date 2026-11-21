<script setup>
import { Head } from '@inertiajs/vue3';
import Button from '@/Components/Ui/Button.vue';
import UiIcon from '@/Components/Ui/UiIcon.vue';
import LevelRule from '@/Components/Ui/LevelRule.vue';
import PublicHeader from '@/Components/PublicHeader.vue';
import PublicFooter from '@/Components/PublicFooter.vue';

const roles = [
    {
        title: 'For the Secretary',
        icon: 'mail',
        intro: 'Send the summons, collect the replies and give the caterer their numbers without a single spreadsheet.',
        points: [
            'Summons and reminders go out on the lodge\'s own schedule',
            'Members reply in one tap, with guests and dietary needs',
            'Dining numbers sent to you and the caterer when replies close',
            'A signing-in book for the door, and attendance over the year',
            'Candidates followed from first enquiry to initiation',
        ],
    },
    {
        title: 'For the Treasurer',
        icon: 'bank',
        intro: 'Books that balance, dues you can see at a glance and a year end the examiner can sign off.',
        points: [
            'Import the bank statement and match each line to what it pays',
            'Dining and tickets paid by card, PayPal or bank transfer',
            'Card payments go straight to the lodge\'s own account',
            'Budgets, reports and the year-end accounts in your financial year',
            'A report for each meeting, ready to read out',
        ],
    },
    {
        title: 'For every member',
        icon: 'users',
        intro: 'One place to see what is coming up, reply, book and pay, on the web or in the app.',
        points: [
            'Every meeting and event across all your lodges and chapters',
            'Reply, add guests and pay for dinner from your phone',
            'Tickets for ladies\' festivals and lodge events',
            'Notices and newsletters from your lodge in one inbox',
            'Sign in with Face ID or your fingerprint in the app',
        ],
    },
];

const features = [
    { icon: 'globe', title: 'A website for your lodge', text: 'Build pages, news and an events list, then publish on your own domain.' },
    { icon: 'ticket', title: 'Events and bookings', text: 'Ticket types, menus, guest lists and a waiting list when it is full.' },
    { icon: 'document', title: 'Documents and signatures', text: 'Share minutes and forms, and collect signatures without paper.' },
    { icon: 'clipboard', title: 'Committee business', text: 'Agendas, notices of motion, tasks and the committee pack.' },
    { icon: 'heart', title: 'Charity and festivals', text: 'Collections, grants and each member\'s festival giving.' },
    { icon: 'shield', title: 'The right access for each officer', text: 'Choose what the Secretary, Treasurer and others can see and change.' },
];

const steps = [
    { title: 'Ask for access', text: 'Tell us about your lodge and your office in it, using the contact form.' },
    { title: 'We check with your lodge', text: 'Before giving access we confirm who you are with your lodge or province, so a lodge is only ever run by its own officers.' },
    { title: 'Bring your members in', text: 'Import your members from a spreadsheet and invite them. They sign in with their own email.' },
];
</script>

<template>
    <Head title="Tracing Board">
        <meta name="description" content="Tracing Board runs the business of your lodge or chapter: summons and replies, dining numbers, dues, accounts, events and your website, in one place." />
    </Head>

    <a href="#main-content" class="sr-only focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-[200] focus:px-4 focus:py-2 focus:rounded-xl focus:bg-blue-600 focus:text-white focus:font-bold focus:text-sm focus:shadow-lg">
        Skip to main content
    </a>

    <div class="tb-public min-h-dvh bg-slate-50 text-slate-800 dark:bg-slate-950 dark:text-slate-200">
        <PublicHeader />

        <main id="main-content" tabindex="-1" class="focus:outline-none">
            <!-- Hero -->
            <section class="mx-auto grid max-w-6xl items-center gap-12 px-4 pt-12 pb-16 sm:px-6 sm:pt-20 sm:pb-24 lg:grid-cols-[1.1fr_1fr]">
                <div>
                    <p class="text-xs font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">For lodges, chapters and their members</p>
                    <h1 class="mt-4 font-display text-4xl font-bold leading-tight tracking-tight text-slate-900 sm:text-6xl dark:text-white">
                        The business of the lodge, laid out on one board.
                    </h1>
                    <div class="mt-6"><LevelRule /></div>
                    <p class="mt-6 max-w-xl text-lg leading-relaxed text-slate-600 dark:text-slate-300">
                        Summons and replies, dining numbers, dues, the accounts, events and your website. Tracing Board takes the paperwork off the Secretary and the Treasurer, and gives every member one place to see what is coming up.
                    </p>
                    <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                        <Button size="lg" :href="route('contact', { topic: 'access' })">Request access for your lodge</Button>
                        <Button size="lg" variant="secondary" :href="route('login')">Sign in as a member</Button>
                    </div>
                    <p class="mt-5 text-sm text-slate-500 dark:text-slate-400">Invitation only for now: we are opening Tracing Board one lodge at a time.</p>
                </div>

                <!-- An illustration of a member's reply screen, built from the same pieces as the real one. -->
                <div class="relative lg:mb-16" role="img" aria-label="Example of a meeting summons with reply buttons and the number of replies so far">
                    <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-xl shadow-slate-900/5 dark:border-slate-800 dark:bg-slate-900" aria-hidden="true">
                        <div class="flex items-start gap-4">
                            <div class="flex h-16 w-16 shrink-0 flex-col items-center justify-center rounded-2xl bg-navy-900 text-white dark:bg-navy-800">
                                <span class="text-[10px] font-bold uppercase tracking-wider text-gold-300">Dec</span>
                                <span class="font-display text-2xl font-bold lining-nums leading-none">1</span>
                            </div>
                            <div class="min-w-0">
                                <p class="text-xs font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">Summons</p>
                                <p class="mt-1 font-display text-xl font-bold text-slate-900 dark:text-white">Regular meeting</p>
                                <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">Tuesday 1 December, 6.30pm. Festive board to follow.</p>
                            </div>
                        </div>
                        <div class="mt-5 rounded-xl bg-slate-100 p-4 text-sm dark:bg-slate-800/60">
                            <p class="font-medium text-slate-800 dark:text-slate-100">Business of the meeting</p>
                            <ul class="mt-2 space-y-1 text-slate-600 dark:text-slate-300">
                                <li>Initiation of Mr David Hughes</li>
                                <li>Election of Master and Treasurer</li>
                                <li>Almoner's report</li>
                            </ul>
                        </div>
                        <p class="mt-5 text-sm font-medium text-slate-800 dark:text-slate-100">Will you be there?</p>
                        <div class="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-3">
                            <span class="rounded-xl bg-blue-600 px-3 py-2 text-center text-sm font-medium text-white">Coming and dining</span>
                            <span class="rounded-xl border border-slate-300 px-3 py-2 text-center text-sm font-medium text-slate-700 dark:border-slate-700 dark:text-slate-200">Not dining</span>
                            <span class="rounded-xl border border-slate-300 px-3 py-2 text-center text-sm font-medium text-slate-700 dark:border-slate-700 dark:text-slate-200">Apologies</span>
                        </div>
                    </div>

                    <div class="mt-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-lg shadow-slate-900/5 lg:absolute lg:-bottom-24 lg:-left-10 lg:mt-0 lg:w-72 dark:border-slate-800 dark:bg-slate-900" aria-hidden="true">
                        <p class="text-xs font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">Secretary's numbers</p>
                        <dl class="mt-3 grid grid-cols-3 gap-2 text-center">
                            <div><dt class="text-xs text-slate-500 dark:text-slate-400">Replied</dt><dd class="font-display text-2xl font-bold tabular-nums lining-nums text-slate-900 dark:text-white">38</dd></div>
                            <div><dt class="text-xs text-slate-500 dark:text-slate-400">Dining</dt><dd class="font-display text-2xl font-bold tabular-nums lining-nums text-slate-900 dark:text-white">26</dd></div>
                            <div><dt class="text-xs text-slate-500 dark:text-slate-400">Paid</dt><dd class="font-display text-2xl font-bold tabular-nums lining-nums text-slate-900 dark:text-white">19</dd></div>
                        </dl>
                    </div>
                </div>
            </section>

            <!-- One section per officer -->
            <section id="features" class="scroll-mt-20 border-y border-slate-200 bg-white py-16 sm:py-24 dark:border-slate-800 dark:bg-slate-900">
                <div class="mx-auto max-w-6xl px-4 sm:px-6">
                    <div class="max-w-2xl">
                        <h2 class="font-display text-3xl font-bold tracking-tight text-slate-900 sm:text-4xl dark:text-white">Built around the lodge year</h2>
                        <div class="mt-4"><LevelRule /></div>
                        <p class="mt-4 text-lg text-slate-600 dark:text-slate-300">Each officer gets the tools for their own job, and the members see only what they need.</p>
                    </div>
                    <div class="mt-12 grid gap-6 lg:grid-cols-3">
                        <article v-for="role in roles" :key="role.title" class="flex flex-col rounded-2xl border border-slate-200 bg-slate-50 p-6 dark:border-slate-800 dark:bg-slate-950/60">
                            <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-navy-900 text-gold-300 dark:bg-navy-800"><UiIcon :name="role.icon" :size="20" /></span>
                            <h3 class="mt-4 font-display text-2xl font-bold text-slate-900 dark:text-white">{{ role.title }}</h3>
                            <p class="mt-2 text-slate-600 dark:text-slate-300">{{ role.intro }}</p>
                            <ul class="mt-5 space-y-3 text-sm">
                                <li v-for="point in role.points" :key="point" class="flex gap-2.5">
                                    <span class="mt-0.5 text-blue-600 dark:text-blue-400"><UiIcon name="check" /></span>
                                    <span class="text-slate-700 dark:text-slate-200">{{ point }}</span>
                                </li>
                            </ul>
                        </article>
                    </div>
                </div>
            </section>

            <!-- Everything else -->
            <section class="mx-auto max-w-6xl px-4 py-16 sm:px-6 sm:py-24">
                <h2 class="font-display text-3xl font-bold tracking-tight text-slate-900 sm:text-4xl dark:text-white">And the rest of lodge life</h2>
                <div class="mt-4"><LevelRule /></div>
                <div class="mt-10 grid gap-x-8 gap-y-10 sm:grid-cols-2 lg:grid-cols-3">
                    <div v-for="feature in features" :key="feature.title" class="flex gap-4">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-slate-200 bg-white text-blue-600 dark:border-slate-800 dark:bg-slate-900 dark:text-blue-400"><UiIcon :name="feature.icon" :size="20" /></span>
                        <div>
                            <h3 class="font-semibold text-slate-900 dark:text-white">{{ feature.title }}</h3>
                            <p class="mt-1 text-sm leading-relaxed text-slate-600 dark:text-slate-300">{{ feature.text }}</p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Getting started -->
            <section class="border-y border-slate-200 bg-white py-16 sm:py-24 dark:border-slate-800 dark:bg-slate-900">
                <div class="mx-auto max-w-6xl px-4 sm:px-6">
                    <h2 class="font-display text-3xl font-bold tracking-tight text-slate-900 sm:text-4xl dark:text-white">Getting your lodge started</h2>
                    <div class="mt-4"><LevelRule /></div>
                    <ol class="mt-10 grid gap-6 md:grid-cols-3">
                        <li v-for="(step, index) in steps" :key="step.title" class="rounded-2xl border border-slate-200 p-6 dark:border-slate-800">
                            <span class="font-display text-4xl font-bold lining-nums text-gold-600 dark:text-gold-400">{{ index + 1 }}</span>
                            <h3 class="mt-3 text-lg font-semibold text-slate-900 dark:text-white">{{ step.title }}</h3>
                            <p class="mt-2 text-sm leading-relaxed text-slate-600 dark:text-slate-300">{{ step.text }}</p>
                        </li>
                    </ol>
                </div>
            </section>

            <!-- Trust -->
            <section class="mx-auto max-w-6xl px-4 py-16 sm:px-6 sm:py-24">
                <div class="grid gap-10 lg:grid-cols-[1fr_1.4fr]">
                    <div>
                        <h2 class="font-display text-3xl font-bold tracking-tight text-slate-900 sm:text-4xl dark:text-white">Your lodge's business stays your lodge's</h2>
                        <div class="mt-4"><LevelRule /></div>
                    </div>
                    <dl class="grid gap-8 sm:grid-cols-2">
                        <div>
                            <dt class="flex items-center gap-2 font-semibold text-slate-900 dark:text-white"><UiIcon name="lock" class="text-blue-600 dark:text-blue-400" />Members only means members only</dt>
                            <dd class="mt-2 text-sm leading-relaxed text-slate-600 dark:text-slate-300">Minutes, accounts and member details are never on your public website. Officers can add two-factor sign-in.</dd>
                        </div>
                        <div>
                            <dt class="flex items-center gap-2 font-semibold text-slate-900 dark:text-white"><UiIcon name="cash" class="text-blue-600 dark:text-blue-400" />Money goes to the lodge</dt>
                            <dd class="mt-2 text-sm leading-relaxed text-slate-600 dark:text-slate-300">Card and PayPal payments are paid into the lodge's own account, and every change to what is paid is logged.</dd>
                        </div>
                        <div>
                            <dt class="flex items-center gap-2 font-semibold text-slate-900 dark:text-white"><UiIcon name="eye-off" class="text-blue-600 dark:text-blue-400" />No tracking</dt>
                            <dd class="mt-2 text-sm leading-relaxed text-slate-600 dark:text-slate-300">No advertising, and no visitor data sent to font or analytics services from your website.</dd>
                        </div>
                        <div>
                            <dt class="flex items-center gap-2 font-semibold text-slate-900 dark:text-white"><UiIcon name="users" class="text-blue-600 dark:text-blue-400" />The right people see the right things</dt>
                            <dd class="mt-2 text-sm leading-relaxed text-slate-600 dark:text-slate-300">Owners decide what each role can do, and only owners can hand over the lodge.</dd>
                        </div>
                    </dl>
                </div>
            </section>

            <!-- Call to action -->
            <section class="px-4 pb-16 sm:px-6 sm:pb-24">
                <div class="mx-auto flex max-w-6xl flex-col items-start gap-8 rounded-2xl bg-navy-900 p-8 sm:p-12 dark:border dark:border-gold-400/30 dark:bg-navy-800 md:flex-row md:items-center md:justify-between">
                    <div class="flex items-start gap-5">
                        <img src="/icons/tracing-board-mark-gold.svg" alt="" class="hidden h-16 w-16 shrink-0 sm:block">
                        <div>
                            <h2 class="font-display text-3xl font-bold tracking-tight text-white">Bring Tracing Board to your lodge</h2>
                            <p class="mt-2 max-w-xl text-slate-300">Tell us about your lodge and we will be in touch. Questions about anything else are welcome too.</p>
                        </div>
                    </div>
                    <div class="flex shrink-0 flex-col gap-3 sm:flex-row">
                        <Button size="lg" :href="route('contact', { topic: 'access' })">Request access</Button>
                        <Button size="lg" variant="secondary" :href="route('contact', { topic: 'question' })">Ask a question</Button>
                    </div>
                </div>
            </section>
        </main>

        <PublicFooter />
    </div>
</template>
