<script setup>
import { ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowRight,
    Check,
    CheckCircle2,
    ChevronDown,
    Code2,
    Globe,
    Headphones,
    Inbox,
    KeyRound,
    Lock,
    Mail,
    Server,
    ShieldCheck,
    Sparkles,
    Webhook,
    X,
} from '@lucide/vue';

defineProps({
    canLogin: Boolean,
    canRegister: Boolean,
});

const openFaq = ref(0);

const headaches = [
    {
        problem: 'Fragmented tools',
        problemBody:
            'Transactional APIs in one place, team inbox in another, and no shared domain reputation.',
        solution: 'One mail platform',
        solutionBody:
            'API, inbox, domains, and deliverability in a single workspace your product and ops teams share.',
    },
    {
        problem: 'Opaque deliverability',
        problemBody:
            'You only find out something broke when customers never get the email.',
        solution: 'Metrics you can act on',
        solutionBody:
            'Delivery, bounce, and complaint rates with logs and suppressions built in.',
    },
    {
        problem: 'Locked-in providers',
        problemBody:
            'Hard-wiring to a single vendor makes failover and cost control painful.',
        solution: 'Provider flexibility',
        solutionBody:
            'Start on Resend. Add SMTP or other providers per organization without rewriting your app.',
    },
];

const tools = [
    {
        icon: Globe,
        title: 'Custom @yourcompany.com email',
        body: 'Professional from-addresses with SPF, DKIM, and DMARC guidance built into domain setup.',
    },
    {
        icon: ShieldCheck,
        title: 'Spam & bounce protection',
        body: 'Hard bounce handling, suppressions, and complaint tracking to protect sender reputation.',
    },
    {
        icon: Lock,
        title: 'Secure by default',
        body: 'API keys, encrypted provider credentials, and audit-friendly message logs.',
    },
    {
        icon: Inbox,
        title: 'Shared team inbox',
        body: 'Threads, replies, and compose for humans — while your product still owns the send API.',
    },
    {
        icon: KeyRound,
        title: 'Developer API & webhooks',
        body: 'Bearer keys, send/receive endpoints, and real-time events for your systems.',
    },
    {
        icon: Server,
        title: 'Admin controls',
        body: 'Workspaces, roles, domains, and usage limits designed for growing teams.',
    },
];

const steps = [
    {
        n: '1',
        title: 'Create a workspace',
        body: 'Sign up and pick Resend or SMTP as your default provider.',
    },
    {
        n: '2',
        title: 'Connect your domain',
        body: 'Add DNS records with a guided checklist for SPF, DKIM, and DMARC.',
    },
    {
        n: '3',
        title: 'Send & receive',
        body: 'Ship via API or compose in the dashboard. Inbox and metrics update live.',
    },
];

const withMailDesk = [
    'API + shared inbox in one product',
    'Domain auth and deliverability metrics',
    'Multi-provider adapters (Resend first)',
    'Webhooks and automation triggers',
    'Transparent pricing as you grow',
    'Human-friendly compose with WYSIWYG',
];

const withoutMailDesk = [
    'Separate tools for send vs inbox',
    'Guesswork when mail fails',
    'Hard rewrites to change providers',
    'Manual polling instead of events',
    'Surprise costs as volume climbs',
    'Poor DX for product engineers',
];

const faqs = [
    {
        q: 'What is MailDesk?',
        a: 'MailDesk is a business mail platform that combines a developer send/receive API with a team inbox — Resend-style DX plus Gmail-like collaboration.',
    },
    {
        q: 'Does MailDesk support SPF, DKIM, and DMARC?',
        a: 'Yes. Domain setup walks you through SPF, DKIM, and DMARC records so your custom domain authenticates cleanly.',
    },
    {
        q: 'Can I use my own mail provider?',
        a: 'Resend is the default. You can also configure SMTP (and more providers later) per organization without changing your app integration.',
    },
    {
        q: 'How do I send email from my app?',
        a: 'Create an API key in the dashboard and POST to /api/v1/emails with a Bearer token. Docs include cURL and SDK examples.',
    },
    {
        q: 'Is there a shared inbox for support teams?',
        a: 'Yes. Inbound and outbound mail threads live in Inbox so humans can reply while automations and webhooks keep systems in sync.',
    },
];

const codeSample = `import { MailDesk } from '@maildesk/sdk';

const md = new MailDesk('md_xxxxxxxx');

await md.emails.send({
  from: 'Acme <hello@acme.com>',
  to: ['user@example.com'],
  subject: 'Hello from MailDesk',
  html: '<strong>It works.</strong>',
});`;

const stats = [
    { value: '99.9%', label: 'Target uptime' },
    { value: 'API + Inbox', label: 'One platform' },
    { value: 'SPF/DKIM', label: 'Domain auth built in' },
    { value: '24×7', label: 'Docs & dashboard' },
];
</script>

<template>
    <Head title="MailDesk — Easy, reliable business email" />

    <div class="min-h-screen overflow-x-hidden bg-black text-zinc-100">
        <div
            class="pointer-events-none absolute inset-x-0 top-0 h-[640px] bg-[radial-gradient(ellipse_at_top,_rgba(34,211,238,0.18),_transparent_55%)]"
        />

        <header
            class="relative z-20 mx-auto flex max-w-6xl items-center justify-between px-6 py-5"
        >
            <div class="flex items-center gap-2">
                <span
                    class="flex h-9 w-9 items-center justify-center rounded-xl bg-cyan-400/15 text-cyan-300"
                >
                    <Mail :size="18" />
                </span>
                <span class="text-lg font-semibold tracking-tight">MailDesk</span>
            </div>
            <nav class="hidden items-center gap-7 text-sm text-zinc-400 md:flex">
                <a href="#problems" class="transition hover:text-white">Why MailDesk</a>
                <a href="#tools" class="transition hover:text-white">Product</a>
                <a href="#api" class="transition hover:text-white">API</a>
                <a href="#faq" class="transition hover:text-white">FAQ</a>
                <Link
                    v-if="$page.props.auth.user"
                    :href="route('dashboard')"
                    class="md-btn-solid"
                >
                    Open app
                </Link>
                <template v-else>
                    <Link :href="route('login')" class="transition hover:text-white"
                        >Log in</Link
                    >
                    <Link :href="route('register')" class="md-btn-primary"
                        >Sign up now</Link
                    >
                </template>
            </nav>
        </header>

        <main class="relative z-10">
            <!-- Hero -->
            <section class="mx-auto max-w-6xl px-6 pb-16 pt-14 lg:grid lg:grid-cols-2 lg:items-center lg:gap-14 lg:pb-24 lg:pt-20">
                <div>
                    <p
                        class="inline-flex items-center gap-2 rounded-full border border-cyan-400/25 bg-cyan-400/10 px-3 py-1 text-xs font-medium text-cyan-300"
                    >
                        <Sparkles :size="12" />
                        Secure & professional business email
                    </p>
                    <h1
                        class="mt-6 max-w-xl text-4xl font-semibold tracking-tight text-white sm:text-5xl lg:text-[3.5rem] lg:leading-[1.08]"
                    >
                        Easy, reliable
                        <span class="text-cyan-300">business email</span>
                    </h1>
                    <p class="mt-5 max-w-lg text-lg leading-relaxed text-zinc-400">
                        Custom domain mail with a developer API, shared inbox,
                        spam-aware deliverability, and provider flexibility —
                        trusted tooling for modern teams.
                    </p>
                    <div class="mt-8 flex flex-wrap gap-3">
                        <Link :href="route('register')" class="md-btn-primary">
                            Sign up now
                            <ArrowRight :size="16" />
                        </Link>
                        <a href="#steps" class="md-btn-ghost">Talk to product</a>
                    </div>
                    <ul class="mt-8 space-y-2.5 text-sm text-zinc-400">
                        <li
                            v-for="item in [
                                'Custom @yourcompany.com with SPF, DKIM, DMARC',
                                'Send & receive via API or dashboard',
                                'Automations, webhooks, and metrics included',
                            ]"
                            :key="item"
                            class="flex items-start gap-2"
                        >
                            <CheckCircle2
                                :size="16"
                                class="mt-0.5 shrink-0 text-cyan-400"
                            />
                            {{ item }}
                        </li>
                    </ul>
                </div>

                <div class="relative mt-14 lg:mt-0">
                    <div
                        class="absolute -inset-8 rounded-[2rem] bg-cyan-400/10 blur-3xl"
                    />
                    <div
                        class="relative overflow-hidden rounded-2xl border border-zinc-800 bg-zinc-950 shadow-2xl shadow-cyan-950/20"
                    >
                        <div
                            class="flex items-center justify-between border-b border-zinc-800 px-4 py-3"
                        >
                            <div class="flex items-center gap-2 text-xs text-zinc-500">
                                <span class="h-2.5 w-2.5 rounded-full bg-rose-500/80" />
                                <span class="h-2.5 w-2.5 rounded-full bg-amber-400/80" />
                                <span class="h-2.5 w-2.5 rounded-full bg-emerald-400/80" />
                                <span class="ml-2">MailDesk inbox</span>
                            </div>
                            <span
                                class="rounded-full bg-emerald-500/15 px-2 py-0.5 text-[10px] font-medium text-emerald-400"
                                >Live</span
                            >
                        </div>
                        <div class="grid sm:grid-cols-[140px_1fr]">
                            <div
                                class="hidden space-y-1 border-r border-zinc-800 bg-black/30 p-3 text-xs text-zinc-500 sm:block"
                            >
                                <div class="rounded-md bg-zinc-800/80 px-2 py-1.5 text-cyan-300">
                                    Inbox
                                </div>
                                <div class="px-2 py-1.5">Sent</div>
                                <div class="px-2 py-1.5">Domains</div>
                                <div class="px-2 py-1.5">API keys</div>
                            </div>
                            <div class="space-y-2 p-3">
                                <div
                                    v-for="row in [
                                        {
                                            from: 'maya@studio.co',
                                            subject: 'Welcome to MailDesk',
                                            status: 'Delivered',
                                            tone: 'text-emerald-400',
                                        },
                                        {
                                            from: 'ops@northwind.io',
                                            subject: 'Domain verified',
                                            status: 'Received',
                                            tone: 'text-cyan-300',
                                        },
                                        {
                                            from: 'billing@acme.com',
                                            subject: 'Receipt #1042',
                                            status: 'Delivered',
                                            tone: 'text-emerald-400',
                                        },
                                    ]"
                                    :key="row.subject"
                                    class="rounded-xl border border-zinc-800/80 bg-black/40 px-3 py-2.5"
                                >
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="truncate text-sm text-zinc-200">{{
                                            row.from
                                        }}</span>
                                        <span class="text-[11px]" :class="row.tone">{{
                                            row.status
                                        }}</span>
                                    </div>
                                    <div class="mt-1 truncate text-xs text-zinc-500">
                                        {{ row.subject }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <p class="relative mt-4 text-center text-sm text-zinc-500">
                        Get started with ad-free, API-first business email.
                    </p>
                </div>
            </section>

            <!-- Stats -->
            <section class="border-y border-zinc-900 bg-zinc-950/40">
                <div
                    class="mx-auto grid max-w-6xl gap-6 px-6 py-10 sm:grid-cols-2 lg:grid-cols-4"
                >
                    <div
                        v-for="stat in stats"
                        :key="stat.label"
                        class="text-center sm:text-left"
                    >
                        <div class="text-2xl font-semibold text-white">
                            {{ stat.value }}
                        </div>
                        <div class="mt-1 text-sm text-zinc-500">
                            {{ stat.label }}
                        </div>
                    </div>
                </div>
            </section>

            <!-- Problems / solutions -->
            <section id="problems" class="mx-auto max-w-6xl px-6 py-24">
                <div class="max-w-2xl">
                    <h2 class="text-3xl font-semibold tracking-tight text-white sm:text-4xl">
                        Three email headaches.
                        <span class="text-cyan-300">Now sorted.</span>
                    </h2>
                    <p class="mt-3 text-zinc-400">
                        Inspired by what businesses need from professional mail —
                        without the bloat of legacy suites.
                    </p>
                </div>

                <div class="mt-12 grid gap-4 lg:grid-cols-3">
                    <div
                        v-for="item in headaches"
                        :key="item.problem"
                        class="overflow-hidden rounded-2xl border border-zinc-800 bg-zinc-950"
                    >
                        <div class="border-b border-zinc-800 bg-rose-500/5 p-5">
                            <p class="text-[11px] font-semibold uppercase tracking-wider text-rose-300/80">
                                Problem
                            </p>
                            <h3 class="mt-2 text-lg font-medium text-white">
                                {{ item.problem }}
                            </h3>
                            <p class="mt-2 text-sm leading-relaxed text-zinc-400">
                                {{ item.problemBody }}
                            </p>
                        </div>
                        <div class="p-5">
                            <p class="text-[11px] font-semibold uppercase tracking-wider text-cyan-300/80">
                                Solution
                            </p>
                            <h3 class="mt-2 text-lg font-medium text-white">
                                {{ item.solution }}
                            </h3>
                            <p class="mt-2 text-sm leading-relaxed text-zinc-400">
                                {{ item.solutionBody }}
                            </p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Tools -->
            <section id="tools" class="border-t border-zinc-900 bg-zinc-950/30 py-24">
                <div class="mx-auto max-w-6xl px-6">
                    <h2 class="text-3xl font-semibold text-white sm:text-4xl">
                        All great tools.
                        <span class="text-zinc-500">Minus the bloat.</span>
                    </h2>
                    <p class="mt-3 max-w-2xl text-zinc-400">
                        Everything you need to host business email and ship
                        product mail — nothing you have to ignore.
                    </p>

                    <div class="mt-12 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <div
                            v-for="tool in tools"
                            :key="tool.title"
                            class="group rounded-2xl border border-zinc-800 bg-black/40 p-6 transition hover:border-cyan-400/30 hover:bg-zinc-950"
                        >
                            <div
                                class="mb-4 flex h-11 w-11 items-center justify-center rounded-xl border border-zinc-800 bg-zinc-950 text-cyan-300 transition group-hover:scale-105 group-hover:border-cyan-400/40"
                            >
                                <component :is="tool.icon" :size="20" />
                            </div>
                            <h3 class="text-base font-medium text-white">
                                {{ tool.title }}
                            </h3>
                            <p class="mt-2 text-sm leading-relaxed text-zinc-400">
                                {{ tool.body }}
                            </p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- 3 steps -->
            <section id="steps" class="mx-auto max-w-6xl px-6 py-24">
                <div class="text-center">
                    <h2 class="text-3xl font-semibold text-white sm:text-4xl">
                        Be ready before your next coffee
                    </h2>
                    <p class="mt-2 text-zinc-400">Go live in 3 steps</p>
                </div>
                <div class="mt-12 grid gap-4 md:grid-cols-3">
                    <div
                        v-for="step in steps"
                        :key="step.n"
                        class="relative rounded-2xl border border-zinc-800 bg-zinc-950 p-6"
                    >
                        <div
                            class="mb-4 flex h-10 w-10 items-center justify-center rounded-full bg-cyan-400 text-sm font-bold text-zinc-950"
                        >
                            {{ step.n }}
                        </div>
                        <h3 class="text-lg font-medium text-white">
                            {{ step.title }}
                        </h3>
                        <p class="mt-2 text-sm leading-relaxed text-zinc-400">
                            {{ step.body }}
                        </p>
                    </div>
                </div>
            </section>

            <!-- Comparison -->
            <section class="border-y border-zinc-900 py-24">
                <div class="mx-auto max-w-6xl px-6">
                    <p class="text-sm font-medium text-zinc-500">Let's face it.</p>
                    <h2 class="mt-2 max-w-2xl text-3xl font-semibold text-white sm:text-4xl">
                        Switching takes a few days. The alternative costs you
                        every day.
                    </h2>

                    <div class="mt-12 grid gap-4 lg:grid-cols-2">
                        <div
                            class="rounded-2xl border border-cyan-400/30 bg-cyan-400/5 p-6 sm:p-8"
                        >
                            <h3 class="text-lg font-medium text-cyan-300">
                                With MailDesk
                            </h3>
                            <ul class="mt-5 space-y-3">
                                <li
                                    v-for="item in withMailDesk"
                                    :key="item"
                                    class="flex items-start gap-2.5 text-sm text-zinc-200"
                                >
                                    <span
                                        class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-cyan-400/20 text-cyan-300"
                                    >
                                        <Check :size="12" />
                                    </span>
                                    {{ item }}
                                </li>
                            </ul>
                        </div>
                        <div class="rounded-2xl border border-zinc-800 bg-zinc-950 p-6 sm:p-8">
                            <h3 class="text-lg font-medium text-zinc-400">
                                Without MailDesk
                            </h3>
                            <ul class="mt-5 space-y-3">
                                <li
                                    v-for="item in withoutMailDesk"
                                    :key="item"
                                    class="flex items-start gap-2.5 text-sm text-zinc-500"
                                >
                                    <span
                                        class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-zinc-800 text-zinc-500"
                                    >
                                        <X :size="12" />
                                    </span>
                                    {{ item }}
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </section>

            <!-- API -->
            <section id="api" class="mx-auto max-w-6xl px-6 py-24">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <div class="flex items-center gap-3">
                            <span
                                class="flex h-10 w-10 items-center justify-center rounded-xl border border-zinc-800 bg-zinc-950 text-cyan-300"
                            >
                                <Code2 :size="18" />
                            </span>
                            <h2 class="text-3xl font-semibold text-white">
                                Integrate this weekend
                            </h2>
                        </div>
                        <p class="mt-3 max-w-xl text-zinc-400">
                            One HTTP API for send and receive. Webhooks for
                            delivery events. Docs in the app.
                        </p>
                    </div>
                    <Link :href="route('register')" class="md-btn-ghost self-start">
                        Get an API key
                        <ArrowRight :size="14" />
                    </Link>
                </div>

                <div class="relative mt-8 overflow-hidden rounded-2xl border border-zinc-800 bg-zinc-950">
                    <div
                        class="flex items-center justify-between border-b border-zinc-800 px-4 py-2 text-xs text-zinc-500"
                    >
                        <span>send.js</span>
                        <span class="inline-flex items-center gap-1 text-cyan-300">
                            <Webhook :size="12" /> API ready
                        </span>
                    </div>
                    <pre
                        class="overflow-x-auto p-5 text-[13px] leading-relaxed text-zinc-300"
                    ><code>{{ codeSample }}</code></pre>
                </div>
            </section>

            <!-- FAQ -->
            <section id="faq" class="border-t border-zinc-900 py-24">
                <div class="mx-auto max-w-3xl px-6">
                    <h2 class="text-center text-3xl font-semibold text-white">
                        Frequently asked questions
                    </h2>
                    <div class="mt-10 divide-y divide-zinc-800 rounded-2xl border border-zinc-800 bg-zinc-950">
                        <div
                            v-for="(faq, i) in faqs"
                            :key="faq.q"
                            class="px-5"
                        >
                            <button
                                type="button"
                                class="flex w-full items-center justify-between gap-4 py-4 text-left"
                                @click="openFaq = openFaq === i ? -1 : i"
                            >
                                <span class="font-medium text-white">{{
                                    faq.q
                                }}</span>
                                <ChevronDown
                                    :size="16"
                                    class="shrink-0 text-zinc-500 transition"
                                    :class="{ 'rotate-180 text-cyan-300': openFaq === i }"
                                />
                            </button>
                            <div
                                v-show="openFaq === i"
                                class="pb-4 text-sm leading-relaxed text-zinc-400"
                            >
                                {{ faq.a }}
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Final CTA -->
            <section class="mx-auto max-w-6xl px-6 pb-24">
                <div
                    class="overflow-hidden rounded-3xl border border-zinc-800 bg-gradient-to-br from-zinc-950 via-black to-cyan-950/50 px-8 py-14 text-center sm:px-12"
                >
                    <div
                        class="mx-auto mb-5 flex h-12 w-12 items-center justify-center rounded-2xl border border-cyan-400/30 bg-cyan-400/10 text-cyan-300"
                    >
                        <Headphones :size="22" />
                    </div>
                    <h2 class="text-3xl font-semibold text-white sm:text-4xl">
                        Host your business email with MailDesk
                    </h2>
                    <p class="mx-auto mt-3 max-w-xl text-zinc-400">
                        Start free today — or explore the dashboard to see
                        domains, API keys, inbox, and metrics.
                    </p>
                    <div class="mt-8 flex flex-wrap justify-center gap-3">
                        <Link :href="route('register')" class="md-btn-primary">
                            Sign up now
                        </Link>
                        <Link :href="route('login')" class="md-btn-ghost">
                            Sign in
                        </Link>
                    </div>
                </div>
            </section>
        </main>

        <footer class="border-t border-zinc-900 py-10">
            <div
                class="mx-auto flex max-w-6xl flex-col items-center justify-between gap-4 px-6 text-xs text-zinc-600 sm:flex-row"
            >
                <div class="flex items-center gap-2 text-zinc-400">
                    <Mail :size="14" class="text-cyan-400" />
                    MailDesk
                </div>
                <div>
                    © {{ new Date().getFullYear() }} MailDesk ·
                    maildesk.test
                </div>
                <div class="flex gap-4">
                    <a href="#tools" class="hover:text-zinc-300">Product</a>
                    <a href="#api" class="hover:text-zinc-300">API</a>
                    <a href="#faq" class="hover:text-zinc-300">FAQ</a>
                </div>
            </div>
        </footer>
    </div>
</template>
