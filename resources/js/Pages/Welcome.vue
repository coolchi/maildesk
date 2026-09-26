<script setup>
import '../../css/landing.css';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import HeroPreview from '@/Components/Landing/HeroPreview.vue';
import { useTheme } from '@/composables/useTheme';
import {
    ArrowRight,
    Check,
    ChevronDown,
    Code2,
    Globe,
    Inbox,
    KeyRound,
    Mail,
    Megaphone,
    Moon,
    Send,
    ShieldCheck,
    Signature,
    Sun,
    Users,
    UsersRound,
    Webhook,
} from '@lucide/vue';

const props = defineProps({
    canLogin: { type: Boolean, default: true },
    canRegister: { type: Boolean, default: true },
});

const page = usePage();
const { isDark, toggle } = useTheme();

const user = computed(() => page.props.auth?.user ?? null);
const primaryHref = computed(() =>
    user.value
        ? route('dashboard')
        : props.canRegister
          ? route('register')
          : route('login'),
);
const primaryLabel = computed(() =>
    user.value ? 'Open your workspace' : 'Create your workspace',
);

const scrolled = ref(false);
const onScroll = () => {
    scrolled.value = window.scrollY > 8;
};
onMounted(() => {
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
});
onUnmounted(() => window.removeEventListener('scroll', onScroll));

const openFaq = ref(0);

const nav = [
    { href: '#features', label: 'Features' },
    { href: '#how-it-works', label: 'How it works' },
    { href: '#developers', label: 'Developers' },
    { href: '#faq', label: 'FAQ' },
];

const highlights = [
    'SPF, DKIM & DMARC checks',
    'REST API with scoped keys',
    'Signed webhooks',
    'Resend or SMTP delivery',
];

// Only features that exist in the product today.
const features = [
    {
        icon: Send,
        title: 'Send from your app or the dashboard',
        body: 'POST to /api/v1/emails with an API key, or write in the rich-text composer with CC, attachments and scheduling.',
    },
    {
        icon: Inbox,
        title: 'Shared team inbox',
        body: 'Inbound mail lands in threads your team can read, reply to and forward — with the original conversation intact.',
    },
    {
        icon: Globe,
        title: 'Domains with DNS verification',
        body: 'Add your domain, publish the records we generate and verify SPF, DKIM and DMARC. Cloudflare users can connect DNS directly.',
    },
    {
        icon: Megaphone,
        title: 'Broadcasts',
        body: 'Send campaigns to your audience with per-recipient delivery tracking and one-click unsubscribe links.',
    },
    {
        icon: Users,
        title: 'Audience & suppressions',
        body: 'Keep contacts in one place. Bounced and complained addresses are suppressed automatically to protect your reputation.',
    },
    {
        icon: Webhook,
        title: 'Webhooks',
        body: 'Get sent, delivered, bounced, complained and received events pushed to your endpoint, signed with HMAC-SHA256.',
    },
    {
        icon: Signature,
        title: 'Signatures',
        body: 'Consistent HTML signatures per mailbox, applied to replies and — if you choose — to API sends.',
    },
    {
        icon: UsersRound,
        title: 'Group addresses',
        body: 'Create team@ or sales@ addresses that fan out incoming mail to every member of the group.',
    },
];

const steps = [
    {
        title: 'Create a workspace',
        body: 'Sign up and create a workspace for your team in a minute.',
    },
    {
        title: 'Connect your domain',
        body: 'Publish the DNS records we generate and verify SPF, DKIM and DMARC in one click.',
    },
    {
        title: 'Send & receive',
        body: 'Create an API key for your app, add mailboxes and start answering mail from the inbox.',
    },
];

const devPoints = [
    { icon: KeyRound, text: 'API keys can be scoped to a single sending domain.' },
    { icon: Webhook, text: 'Delivery and inbound events with signed payloads.' },
    { icon: ShieldCheck, text: 'Automatic suppression of bounced and complained addresses.' },
];

const faqs = [
    {
        q: 'What is MailDesk?',
        a: 'MailDesk combines a developer send API with a shared team inbox. Your product sends transactional mail through the API while your team reads, replies to and forwards mail in the same workspace.',
    },
    {
        q: 'Does MailDesk support SPF, DKIM and DMARC?',
        a: 'Yes. When you add a domain, MailDesk generates the DNS records and checks SPF, DKIM and DMARC so your mail authenticates cleanly. If your DNS is on Cloudflare you can connect it and publish records directly.',
    },
    {
        q: 'Which delivery providers can I use?',
        a: 'Workspaces send through Resend by default, and you can configure your own SMTP server instead — without changing how your app calls the MailDesk API.',
    },
    {
        q: 'How do I send email from my app?',
        a: 'Create an API key in the dashboard and POST JSON to /api/v1/emails with a Bearer token. The in-app docs include cURL examples for sending, reading the inbox and managing domains.',
    },
    {
        q: 'Can my whole team work from the inbox?',
        a: 'Yes. Add mailboxes for your team, set up group addresses like support@, and reply or forward from shared threads with your mailbox signature applied.',
    },
];
</script>

<template>
    <Head title="MailDesk — Business email and a developer API in one workspace" />

    <div class="lp relative min-h-screen overflow-x-hidden font-sans antialiased">
        <!-- Header -->
        <header
            class="lp-header sticky top-0 z-40"
            :class="{ 'is-scrolled': scrolled }"
        >
            <div
                class="mx-auto flex h-16 max-w-6xl items-center justify-between gap-4 px-5 sm:px-6"
            >
                <Link href="/" class="flex items-center gap-2.5" aria-label="MailDesk home">
                    <span
                        class="lp-logo flex h-8 w-8 items-center justify-center rounded-lg"
                    >
                        <Mail :size="16" />
                    </span>
                    <span class="lp-text text-[17px] font-semibold tracking-tight"
                        >MailDesk</span
                    >
                </Link>

                <nav class="hidden items-center gap-7 text-sm md:flex" aria-label="Primary">
                    <a
                        v-for="item in nav"
                        :key="item.href"
                        :href="item.href"
                        class="lp-nav-link"
                        >{{ item.label }}</a
                    >
                </nav>

                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        class="lp-icon-btn flex h-9 w-9 items-center justify-center rounded-full"
                        :aria-label="isDark ? 'Switch to light mode' : 'Switch to dark mode'"
                        data-testid="landing-theme-toggle"
                        @click="toggle"
                    >
                        <Sun v-if="isDark" :size="16" />
                        <Moon v-else :size="16" />
                    </button>
                    <Link
                        v-if="user"
                        :href="route('dashboard')"
                        class="lp-btn lp-btn-primary px-4 py-2 text-sm"
                    >
                        Open app
                    </Link>
                    <template v-else>
                        <Link
                            v-if="canLogin"
                            :href="route('login')"
                            class="lp-nav-link px-2 text-sm font-medium"
                            >Log in</Link
                        >
                        <Link
                            v-if="canRegister"
                            :href="route('register')"
                            class="lp-btn lp-btn-primary px-4 py-2 text-sm"
                            >Sign up</Link
                        >
                    </template>
                </div>
            </div>
        </header>

        <main>
            <!-- Hero -->
            <section class="relative">
                <div class="lp-glow pointer-events-none absolute inset-x-0 top-0 h-[720px]" />
                <div class="lp-grid-bg pointer-events-none absolute inset-x-0 top-0 h-[620px]" />

                <div
                    class="relative mx-auto grid max-w-6xl items-center gap-16 px-5 pb-20 pt-14 sm:px-6 sm:pt-20 lg:grid-cols-[1.05fr_1fr] lg:gap-12 lg:pb-28 lg:pt-24"
                >
                    <div>
                        <p
                            class="lp-pill inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-medium"
                        >
                            <span class="h-1.5 w-1.5 rounded-full bg-current" />
                            Business email + developer API
                        </p>
                        <h1
                            class="lp-text mt-6 text-[2.5rem] font-semibold leading-[1.06] tracking-tight sm:text-5xl lg:text-[3.5rem]"
                        >
                            Business email your app
                            <span class="lp-accent">and your team</span> can share.
                        </h1>
                        <p class="lp-text-2 mt-6 max-w-xl text-lg leading-relaxed">
                            Send transactional mail with a simple API, answer
                            customers from a shared inbox, and authenticate your
                            domain with guided DNS — all in one MailDesk
                            workspace.
                        </p>
                        <div class="mt-9 flex flex-col gap-3 sm:flex-row">
                            <Link
                                :href="primaryHref"
                                class="lp-btn lp-btn-primary px-6 py-3 text-[15px]"
                                data-testid="landing-primary-cta"
                            >
                                {{ primaryLabel }}
                                <ArrowRight :size="16" />
                            </Link>
                            <a
                                href="#features"
                                class="lp-btn lp-btn-secondary px-6 py-3 text-[15px]"
                            >
                                See what's included
                            </a>
                        </div>
                        <ul class="mt-10 grid gap-x-6 gap-y-2.5 text-sm sm:grid-cols-2">
                            <li
                                v-for="item in highlights"
                                :key="item"
                                class="lp-text-2 flex items-center gap-2"
                            >
                                <Check :size="15" class="lp-accent shrink-0" />
                                {{ item }}
                            </li>
                        </ul>
                    </div>

                    <div class="px-2 sm:px-6 lg:px-0">
                        <HeroPreview />
                    </div>
                </div>
            </section>

            <!-- Features -->
            <section id="features" class="lp-bg-alt lp-hairline scroll-mt-16 border-y py-20 sm:py-28">
                <div class="mx-auto max-w-6xl px-5 sm:px-6">
                    <div class="max-w-2xl">
                        <p class="lp-eyebrow">Features</p>
                        <h2
                            class="lp-text mt-3 text-3xl font-semibold tracking-tight sm:text-4xl"
                        >
                            Everything you need to send, receive and stay
                            deliverable.
                        </h2>
                        <p class="lp-text-2 mt-4 text-lg leading-relaxed">
                            One workspace for product mail and the humans who
                            answer it — no stitching tools together.
                        </p>
                    </div>

                    <div class="mt-12 grid gap-4 sm:mt-16 sm:grid-cols-2 lg:grid-cols-4">
                        <article
                            v-for="f in features"
                            :key="f.title"
                            class="lp-card lp-card-hover rounded-2xl p-6"
                        >
                            <span
                                class="lp-icon-tile flex h-10 w-10 items-center justify-center rounded-xl"
                            >
                                <component :is="f.icon" :size="18" />
                            </span>
                            <h3 class="lp-text mt-5 text-base font-semibold leading-snug">
                                {{ f.title }}
                            </h3>
                            <p class="lp-text-2 mt-2 text-sm leading-relaxed">
                                {{ f.body }}
                            </p>
                        </article>
                    </div>
                </div>
            </section>

            <!-- How it works -->
            <section id="how-it-works" class="scroll-mt-16 py-20 sm:py-28">
                <div class="mx-auto max-w-6xl px-5 sm:px-6">
                    <div class="mx-auto max-w-2xl text-center">
                        <p class="lp-eyebrow">How it works</p>
                        <h2
                            class="lp-text mt-3 text-3xl font-semibold tracking-tight sm:text-4xl"
                        >
                            Live in three steps
                        </h2>
                        <p class="lp-text-2 mt-4 text-lg leading-relaxed">
                            From sign-up to your first authenticated email in
                            an afternoon.
                        </p>
                    </div>
                    <ol class="mt-12 grid gap-4 sm:mt-16 md:grid-cols-3">
                        <li
                            v-for="(step, i) in steps"
                            :key="step.title"
                            class="lp-card rounded-2xl p-6 sm:p-7"
                        >
                            <span
                                class="lp-step-num flex h-9 w-9 items-center justify-center rounded-full text-sm font-bold"
                                >{{ i + 1 }}</span
                            >
                            <h3 class="lp-text mt-5 text-lg font-semibold">
                                {{ step.title }}
                            </h3>
                            <p class="lp-text-2 mt-2 text-sm leading-relaxed">
                                {{ step.body }}
                            </p>
                        </li>
                    </ol>
                </div>
            </section>

            <!-- Developers -->
            <section
                id="developers"
                class="lp-bg-alt lp-hairline scroll-mt-16 border-y py-20 sm:py-28"
            >
                <div
                    class="mx-auto grid max-w-6xl items-center gap-12 px-5 sm:px-6 lg:grid-cols-[1fr_1.15fr] lg:gap-16"
                >
                    <div>
                        <p class="lp-eyebrow">Developers</p>
                        <h2
                            class="lp-text mt-3 text-3xl font-semibold tracking-tight sm:text-4xl"
                        >
                            One HTTP call to send.
                        </h2>
                        <p class="lp-text-2 mt-4 text-lg leading-relaxed">
                            A small, predictable REST API for sending, reading
                            inbox threads and managing domains — documented
                            inside the app.
                        </p>
                        <ul class="mt-8 space-y-4">
                            <li
                                v-for="p in devPoints"
                                :key="p.text"
                                class="flex items-start gap-3"
                            >
                                <span
                                    class="lp-icon-tile mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-lg"
                                >
                                    <component :is="p.icon" :size="14" />
                                </span>
                                <span class="lp-text-2 text-[15px] leading-relaxed">{{
                                    p.text
                                }}</span>
                            </li>
                        </ul>
                        <Link
                            :href="primaryHref"
                            class="lp-btn lp-btn-secondary mt-9 px-5 py-2.5 text-sm"
                        >
                            <Code2 :size="15" />
                            Get an API key
                        </Link>
                    </div>

                    <div class="lp-code lp-float overflow-hidden rounded-2xl">
                        <div
                            class="lp-code-bar flex items-center justify-between border-b px-4 py-2.5 text-xs"
                        >
                            <span class="font-mono">send-email.sh</span>
                            <span>cURL</span>
                        </div>
                        <pre
                            class="overflow-x-auto p-5 font-mono text-[13px] leading-6"
                        ><code><span class="c"># Send a transactional email</span>
curl -X POST https://your-workspace/api/v1/emails \
  -H <span class="s">"Authorization: Bearer md_your_api_key"</span> \
  -H <span class="s">"Content-Type: application/json"</span> \
  -d '{
    <span class="k">"from"</span>: <span class="s">"Acme &lt;hello@acme.com&gt;"</span>,
    <span class="k">"to"</span>: <span class="s">"customer@example.com"</span>,
    <span class="k">"subject"</span>: <span class="s">"Your receipt"</span>,
    <span class="k">"html"</span>: <span class="s">"&lt;p&gt;Thanks for your order!&lt;/p&gt;"</span>
  }'</code></pre>
                    </div>
                </div>
            </section>

            <!-- FAQ -->
            <section id="faq" class="scroll-mt-16 py-20 sm:py-28">
                <div class="mx-auto max-w-3xl px-5 sm:px-6">
                    <div class="text-center">
                        <p class="lp-eyebrow">FAQ</p>
                        <h2
                            class="lp-text mt-3 text-3xl font-semibold tracking-tight sm:text-4xl"
                        >
                            Frequently asked questions
                        </h2>
                    </div>
                    <div class="lp-card mt-12 overflow-hidden rounded-2xl">
                        <div
                            v-for="(faq, i) in faqs"
                            :key="faq.q"
                            class="lp-faq border-b px-5 last:border-b-0 sm:px-6"
                        >
                            <h3>
                                <button
                                    :id="`faq-q-${i}`"
                                    type="button"
                                    class="lp-faq-q lp-text flex w-full items-center justify-between gap-4 py-5 text-left text-[15px] font-medium transition"
                                    :aria-expanded="openFaq === i"
                                    :aria-controls="`faq-a-${i}`"
                                    @click="openFaq = openFaq === i ? -1 : i"
                                >
                                    {{ faq.q }}
                                    <ChevronDown
                                        :size="18"
                                        class="lp-muted shrink-0 transition-transform duration-200"
                                        :class="{ 'rotate-180': openFaq === i }"
                                    />
                                </button>
                            </h3>
                            <div
                                v-show="openFaq === i"
                                :id="`faq-a-${i}`"
                                role="region"
                                :aria-labelledby="`faq-q-${i}`"
                                class="lp-text-2 pb-5 text-[15px] leading-relaxed"
                            >
                                {{ faq.a }}
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Closing CTA -->
            <section class="px-5 pb-20 sm:px-6 sm:pb-28">
                <div
                    class="lp-cta mx-auto max-w-6xl rounded-3xl px-6 py-14 text-center sm:px-12 sm:py-20"
                >
                    <h2
                        class="lp-text mx-auto max-w-2xl text-3xl font-semibold tracking-tight sm:text-4xl"
                    >
                        Give your team and your app one place for email.
                    </h2>
                    <p class="lp-text-2 mx-auto mt-4 max-w-xl text-lg leading-relaxed">
                        Create a workspace, verify your domain and send your
                        first email. You can choose a plan from your workspace
                        settings whenever you're ready.
                    </p>
                    <div class="mt-9 flex flex-col justify-center gap-3 sm:flex-row">
                        <Link
                            :href="primaryHref"
                            class="lp-btn lp-btn-primary px-6 py-3 text-[15px]"
                            data-testid="landing-closing-cta"
                        >
                            {{ primaryLabel }}
                            <ArrowRight :size="16" />
                        </Link>
                        <Link
                            v-if="!user && canLogin"
                            :href="route('login')"
                            class="lp-btn lp-btn-secondary px-6 py-3 text-[15px]"
                        >
                            Log in
                        </Link>
                    </div>
                </div>
            </section>
        </main>

        <!-- Footer -->
        <footer class="lp-hairline border-t">
            <div
                class="mx-auto grid max-w-6xl gap-10 px-5 py-12 sm:px-6 md:grid-cols-[1.5fr_1fr_1fr]"
            >
                <div>
                    <div class="flex items-center gap-2.5">
                        <span
                            class="lp-logo flex h-8 w-8 items-center justify-center rounded-lg"
                        >
                            <Mail :size="16" />
                        </span>
                        <span class="lp-text font-semibold tracking-tight">MailDesk</span>
                    </div>
                    <p class="lp-text-2 mt-4 max-w-xs text-sm leading-relaxed">
                        Business email and a developer API in one workspace.
                    </p>
                </div>
                <div>
                    <h3 class="lp-text text-sm font-semibold">Product</h3>
                    <ul class="mt-4 space-y-2.5 text-sm">
                        <li v-for="item in nav" :key="item.href">
                            <a :href="item.href" class="lp-footer-link">{{ item.label }}</a>
                        </li>
                    </ul>
                </div>
                <div>
                    <h3 class="lp-text text-sm font-semibold">Account</h3>
                    <ul class="mt-4 space-y-2.5 text-sm">
                        <template v-if="user">
                            <li>
                                <Link :href="route('dashboard')" class="lp-footer-link"
                                    >Open app</Link
                                >
                            </li>
                        </template>
                        <template v-else>
                            <li v-if="canLogin">
                                <Link :href="route('login')" class="lp-footer-link">Log in</Link>
                            </li>
                            <li v-if="canRegister">
                                <Link :href="route('register')" class="lp-footer-link"
                                    >Create a workspace</Link
                                >
                            </li>
                        </template>
                    </ul>
                </div>
            </div>
            <div class="lp-hairline border-t">
                <div
                    class="lp-muted mx-auto flex max-w-6xl flex-col gap-2 px-5 py-6 text-xs sm:flex-row sm:items-center sm:justify-between sm:px-6"
                >
                    <span>© {{ new Date().getFullYear() }} MailDesk. All rights reserved.</span>
                    <span>Made for teams that live in their inbox.</span>
                </div>
            </div>
        </footer>
    </div>
</template>
