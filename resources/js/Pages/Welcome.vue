<script setup>
import '../../css/landing.css';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { useTheme } from '@/composables/useTheme';
import BrandLogo from '@/Components/BrandLogo.vue';
import {
    ArrowRight,
    ChevronDown,
    Menu,
    Moon,
    Sun,
    X,
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
const menuOpen = ref(false);
const activeNav = ref('');
const updateActiveNav = () => {
    const mark = window.innerHeight * 0.28;
    let current = '';
    for (const item of nav) {
        const section = document.querySelector(item.href);
        if (!section) continue;
        if (section.getBoundingClientRect().top <= mark) current = item.href;
    }
    activeNav.value = current;
};
const onScroll = () => {
    scrolled.value = window.scrollY > 8;
    updateActiveNav();
};
const closeMenu = () => {
    menuOpen.value = false;
};
const onKeydown = (event) => {
    if (event.key === 'Escape') closeMenu();
};

const heroStage = ref(null);
let tiltEnabled = false;

const onHeroPointerMove = (event) => {
    const stage = heroStage.value;
    if (!stage || !tiltEnabled) return;

    const rect = stage.getBoundingClientRect();
    const dx = (event.clientX - (rect.left + rect.width / 2)) / rect.width;
    const dy = (event.clientY - (rect.top + rect.height / 2)) / rect.height;
    const x = Math.max(-0.85, Math.min(0.85, dx));
    const y = Math.max(-0.85, Math.min(0.85, dy));

    stage.style.setProperty('--rx', `${(y * 22).toFixed(2)}deg`);
    stage.style.setProperty('--ry', `${(-x * 28).toFixed(2)}deg`);
    stage.style.setProperty('--px', x.toFixed(3));
    stage.style.setProperty('--py', y.toFixed(3));
    stage.style.setProperty('--gx', `${(50 + x * 72).toFixed(1)}%`);
    stage.style.setProperty('--gy', `${(40 + y * 64).toFixed(1)}%`);
    stage.classList.add('is-active');
};

const resetHeroTilt = () => {
    const stage = heroStage.value;
    if (!stage) return;
    stage.style.setProperty('--rx', '0deg');
    stage.style.setProperty('--ry', '0deg');
    stage.style.setProperty('--px', '0');
    stage.style.setProperty('--py', '0');
    stage.style.setProperty('--gx', '46%');
    stage.style.setProperty('--gy', '28%');
    stage.classList.remove('is-active');
};

let revealObserver;
onMounted(() => {
    window.addEventListener('keydown', onKeydown);

    tiltEnabled = window.matchMedia(
        '(hover: hover) and (pointer: fine) and (prefers-reduced-motion: no-preference)',
    ).matches;

    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });

    const nodes = document.querySelectorAll('.lp-reveal');
    const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (reduce || !('IntersectionObserver' in window)) {
        nodes.forEach((node) => node.classList.add('is-shown'));
        return;
    }

    revealObserver = new IntersectionObserver(
        (entries) => {
            entries.forEach((entry) => {
                if (!entry.isIntersecting) return;
                entry.target.classList.add('is-shown');
                const settle = (event) => {
                    if (event.target !== entry.target) return;
                    entry.target.classList.add('is-settled');
                    entry.target.removeEventListener('animationend', settle);
                };
                entry.target.addEventListener('animationend', settle);
                revealObserver.unobserve(entry.target);
            });
        },
        { threshold: 0.18, rootMargin: '0px 0px -32px 0px' },
    );
    nodes.forEach((node) => revealObserver.observe(node));
});
onUnmounted(() => {
    window.removeEventListener('scroll', onScroll);
    window.removeEventListener('keydown', onKeydown);
    revealObserver?.disconnect();
});

const openFaq = ref(0);

const nav = [
    { href: '#ai', label: 'AI' },
    { href: '#features', label: 'Product' },
    { href: '#developers', label: 'Developers' },
    { href: '/docs/send', label: 'Docs' },
];

const aiPoints = [
    {
        icon: '/images/landing/icon-ai.png',
        title: 'Triage',
        body: 'Every inbound message is labeled by priority, intent and language.',
    },
    {
        icon: '/images/landing/icon-shield.png',
        title: 'Spam',
        body: 'Scams and junk leave the inbox and sit in Spam until you say otherwise.',
    },
    {
        icon: '/images/landing/icon-inbox.png',
        title: 'Drafts',
        body: 'A reply in the thread’s tone, ready to edit. Nothing sends itself.',
    },
];

const features = [
    {
        icon: '/images/landing/icon-send.png',
        title: 'Send',
        body: 'One POST from your app, or compose in the workspace.',
    },
    {
        icon: '/images/landing/icon-inbox.png',
        title: 'Inbox',
        body: 'The team reads, replies and forwards in the same thread.',
    },
    {
        icon: '/images/landing/icon-domain.png',
        title: 'Domains',
        body: 'SPF, DKIM and DMARC, checked before the domain is called ready.',
    },
    {
        icon: '/images/landing/icon-api.png',
        title: 'Webhooks',
        body: 'Delivery and inbound events, signed, pushed to your endpoint.',
    },
    {
        icon: '/images/landing/icon-marketing.png',
        title: 'Email marketing',
        body: 'One message to a list, a group, or the whole audience.',
    },
    {
        icon: '/images/landing/icon-automation.png',
        title: 'Automation',
        body: 'A trigger, a wait, a send. The workflow runs on its own.',
    },
    {
        icon: '/images/landing/icon-group.png',
        title: 'Group mail',
        body: 'A shared address. The team reads and replies in one thread.',
    },
    {
        icon: '/images/landing/icon-template.png',
        title: 'Templates',
        body: 'Design the message once. Reuse it in every send.',
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
    'Keys can be scoped to one sending domain.',
    'Delivery and inbound events arrive signed.',
    'Bounces and complaints are suppressed on their own.',
];

const faqs = [
    {
        q: 'What is MailDesk?',
        a: 'AI-powered business email for organizations and developers. The product sends through the API. The team works the same mail in a shared inbox.',
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
        a: 'Create an API key in the dashboard and POST JSON to /api/v1/emails with a Bearer token. The public send guide at /docs/send has the request, the response, and a prompt you can paste into an AI assistant.',
    },
    {
        q: 'Can my whole team work from the inbox?',
        a: 'Yes. Add mailboxes for your team, set up group addresses like support@, and reply or forward from shared threads with your mailbox signature applied.',
    },
];
</script>

<template>
    <Head title="MailDesk — AI-powered business email" />

    <div class="lp relative min-h-screen overflow-x-hidden font-sans antialiased">
        <!-- Header -->
        <header
            class="lp-header sticky top-0 z-40"
            :class="{ 'is-scrolled': scrolled, 'is-open': menuOpen }"
        >
            <div class="lp-header-bar">
                <Link href="/" class="lp-header-logo flex items-center" aria-label="MailDesk home">
                    <BrandLogo class="h-9" />
                </Link>

                <nav class="lp-header-nav" aria-label="Primary">
                    <a
                        v-for="item in nav"
                        :key="item.href"
                        :href="item.href"
                        class="lp-nav-link"
                        :class="{ 'is-current': activeNav === item.href }"
                        :aria-current="activeNav === item.href ? 'true' : undefined"
                        >{{ item.label }}</a
                    >
                </nav>

                <div class="lp-header-actions">
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
                    <span class="lp-header-rule" aria-hidden="true" />
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
                            class="lp-nav-link lp-header-login px-2 text-sm font-medium"
                            >Log in</Link
                        >
                        <Link
                            v-if="canRegister"
                            :href="route('register')"
                            class="lp-btn lp-btn-primary px-4 py-2 text-sm"
                            >Sign up</Link
                        >
                    </template>
                    <button
                        type="button"
                        class="lp-icon-btn lp-header-menu flex h-9 w-9 items-center justify-center rounded-full"
                        :aria-expanded="menuOpen ? 'true' : 'false'"
                        aria-controls="lp-mobile-nav"
                        :aria-label="menuOpen ? 'Close menu' : 'Open menu'"
                        @click="menuOpen = !menuOpen"
                    >
                        <X v-if="menuOpen" :size="16" />
                        <Menu v-else :size="16" />
                    </button>
                </div>
            </div>

            <nav
                id="lp-mobile-nav"
                v-show="menuOpen"
                class="lp-header-drawer"
                aria-label="Mobile"
            >
                <a
                    v-for="item in nav"
                    :key="item.href"
                    :href="item.href"
                    class="lp-nav-link"
                    :class="{ 'is-current': activeNav === item.href }"
                    :aria-current="activeNav === item.href ? 'true' : undefined"
                    @click="closeMenu"
                    >{{ item.label }}</a
                >
                <Link
                    v-if="!user && canLogin"
                    :href="route('login')"
                    class="lp-nav-link"
                    @click="closeMenu"
                    >Log in</Link
                >
            </nav>
        </header>

        <main>
            <!-- Hero -->
            <section
                class="lp-hero relative"
                @pointermove="onHeroPointerMove"
                @pointerleave="resetHeroTilt"
            >
                <div class="lp-glow pointer-events-none absolute inset-x-0 top-0 h-full" />

                <div
                    class="relative mx-auto grid w-full max-w-6xl items-center gap-6 px-5 py-16 sm:px-6 lg:grid-cols-[1.05fr_0.95fr] lg:gap-8 lg:py-10"
                >
                    <div>
                        <h1 class="lp-display lp-hero-title lp-text lp-rise" style="--d: 0.05s">
                            Business Mail,<br /><em>with AI mind.</em>
                        </h1>
                        <p class="lp-text-2 lp-rise mt-8 max-w-md text-lg leading-snug sm:text-xl" style="--d: 0.18s">
                            Smart business email for organizations and developers.
                        </p>
                        <div class="lp-rise mt-10" style="--d: 0.32s">
                            <Link
                                :href="primaryHref"
                                class="lp-btn lp-btn-primary px-6 py-3 text-[15px]"
                                data-testid="landing-primary-cta"
                            >
                                {{ primaryLabel }}
                                <ArrowRight :size="16" />
                            </Link>
                        </div>
                    </div>

                    <div ref="heroStage" class="lp-hero-stage">
                        <div class="lp-hero-float">
                            <div class="lp-hero-tilt">
                                <img
                                    src="/images/landing/hero-envelope.png"
                                    alt=""
                                    class="lp-hero-figure"
                                    width="960"
                                    height="960"
                                    draggable="false"
                                />
                                <span class="lp-hero-glare" aria-hidden="true" />
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- AI -->
            <section id="ai" class="scroll-mt-16 pb-20 sm:pb-28">
                <div class="mx-auto grid max-w-6xl gap-12 px-5 sm:px-6 lg:grid-cols-[0.9fr_1.1fr] lg:items-end lg:gap-20">
                    <div class="lp-reveal">
                        <p class="lp-eyebrow">The AI</p>
                        <h2 class="lp-display lp-text mt-4 text-5xl sm:text-6xl">
                            Sorted before you open.
                        </h2>
                    </div>
                    <div class="lp-reveal" style="--d: 0.12s">
                        <p class="lp-text-2 text-lg leading-relaxed">
                            Organizations get an inbox that sorts itself. Developers keep one API. The model triages, files junk, and drafts — you still send.
                        </p>
                        <ul class="mt-10 space-y-6">
                            <li
                                v-for="(point, i) in aiPoints"
                                :key="point.title"
                                class="lp-reveal flex items-center gap-5"
                                :style="{ '--d': `${0.08 + i * 0.08}s` }"
                            >
                                <img
                                    :src="point.icon"
                                    alt=""
                                    class="lp-glass-icon shrink-0"
                                    width="72"
                                    height="72"
                                />
                                <div>
                                    <h3 class="lp-text text-base font-medium">{{ point.title }}</h3>
                                    <p class="lp-text-2 mt-1 text-sm leading-relaxed">{{ point.body }}</p>
                                </div>
                            </li>
                        </ul>
                    </div>
                </div>
            </section>

            <!-- Features -->
            <section id="features" class="lp-bg-alt lp-hairline scroll-mt-16 border-y py-20 sm:py-28">
                <div class="mx-auto max-w-6xl px-5 sm:px-6">
                    <div class="lp-reveal max-w-2xl">
                        <p class="lp-eyebrow">Features</p>
                        <h2 class="lp-display lp-text mt-4 text-5xl sm:text-6xl">
                            The rest of the desk.
                        </h2>
                    </div>

                    <div class="mt-14 grid gap-4 sm:grid-cols-2">
                        <article
                            v-for="(f, i) in features"
                            :key="f.title"
                            class="lp-card lp-card-hover lp-reveal flex items-center gap-5 rounded-2xl p-6"
                            :style="{ '--d': `${(i % 2) * 0.08}s` }"
                        >
                            <img
                                :src="f.icon"
                                alt=""
                                class="lp-glass-icon shrink-0"
                                width="72"
                                height="72"
                            />
                            <div>
                            <h3 class="lp-text text-base font-medium">
                                {{ f.title }}
                            </h3>
                            <p class="lp-text-2 mt-1 text-sm leading-relaxed">
                                {{ f.body }}
                            </p>
                            </div>
                        </article>
                    </div>
                </div>
            </section>

            <!-- How it works -->
            <section id="how-it-works" class="scroll-mt-16 py-20 sm:py-28">
                <div class="mx-auto max-w-6xl px-5 sm:px-6">
                    <div class="lp-reveal mx-auto max-w-2xl text-center">
                        <p class="lp-eyebrow">How it works</p>
                        <h2 class="lp-display lp-text mt-4 text-5xl sm:text-6xl">
                            Three steps.
                        </h2>
                    </div>
                    <ol class="mt-12 grid gap-4 sm:mt-16 md:grid-cols-3">
                        <li
                            v-for="(step, i) in steps"
                            :key="step.title"
                            class="lp-card lp-reveal rounded-2xl p-6 sm:p-7"
                            :style="{ '--d': `${i * 0.1}s` }"
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
                    <div class="lp-reveal">
                        <p class="lp-eyebrow">Developers</p>
                        <h2 class="lp-display lp-text mt-4 text-5xl sm:text-6xl">
                            One call to send.
                        </h2>
                        <ul class="mt-8 space-y-3">
                            <li
                                v-for="point in devPoints"
                                :key="point"
                                class="lp-text-2 text-[15px] leading-relaxed"
                            >
                                {{ point }}
                            </li>
                        </ul>
                        <div class="mt-9 flex flex-wrap items-center gap-3">
                            <Link
                                :href="primaryHref"
                                class="lp-btn lp-btn-secondary px-5 py-2.5 text-sm"
                            >
                                Get an API key
                            </Link>
                            <Link
                                href="/docs/send"
                                class="lp-btn lp-btn-secondary px-5 py-2.5 text-sm"
                            >
                                Send API quick start
                            </Link>
                        </div>
                    </div>

                    <div class="lp-code lp-float lp-reveal overflow-hidden rounded-2xl" style="--d: 0.12s">
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
                    <div class="lp-reveal text-center">
                        <p class="lp-eyebrow">FAQ</p>
                        <h2 class="lp-display lp-text mt-4 text-5xl sm:text-6xl">
                            Questions
                        </h2>
                    </div>
                    <div class="lp-card lp-reveal mt-12 overflow-hidden rounded-2xl" style="--d: 0.08s">
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
                    class="lp-cta lp-reveal mx-auto max-w-6xl rounded-3xl px-6 py-14 text-center sm:px-12 sm:py-20"
                >
                    <h2 class="lp-display lp-text mx-auto max-w-3xl text-5xl sm:text-7xl">
                        Open a workspace.
                    </h2>
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
                    <BrandLogo class="h-8" />
                    <p class="lp-text-2 mt-4 max-w-xs text-sm leading-relaxed">
                        Smart business email for organizations and developers.
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
                    <span>Organizations and developers.</span>
                </div>
            </div>
        </footer>
    </div>
</template>
