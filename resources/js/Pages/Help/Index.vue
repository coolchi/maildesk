<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import { useOnboarding } from '@/composables/useOnboarding';
import {
    BookOpen,
    Check,
    ChevronDown,
    Globe,
    KeyRound,
    LifeBuoy,
    Send,
    Webhook,
} from '@lucide/vue';

const props = defineProps({
    appName: { type: String, default: 'MailDesk' },
    supportEmail: { type: String, default: null },
    paymentsConfigured: { type: Boolean, default: false },
});

const onboarding = useOnboarding();
const isDone = (key) => Boolean(onboarding.steps.value?.[key]);

// Mirrors the "Get started" checklist (ONBOARDING_STEPS in useOnboarding.js).
const steps = [
    {
        key: 'domain',
        title: 'Add and verify a domain',
        body: 'Add the domain you send from, publish the DNS records we show you, then click Verify.',
        icon: Globe,
        route: 'domains',
        cta: 'Open Domains',
    },
    {
        key: 'apiKey',
        title: 'Create an API key',
        body: 'Needed to send from your own code. The key is shown once, so copy it somewhere safe.',
        icon: KeyRound,
        route: 'api-keys',
        cta: 'Open API Keys',
    },
    {
        key: 'send',
        title: 'Send your first email',
        body: 'Use Compose in the app, or POST to the API with your key.',
        icon: Send,
        route: 'compose',
        cta: 'Compose',
    },
    {
        key: 'webhook',
        title: 'Set up a webhook',
        body: 'Get notified when mail is sent, delivered, bounced or received.',
        icon: Webhook,
        route: 'webhooks',
        cta: 'Open Webhooks',
    },
];

// Every answer describes what the app does today; unfinished parts are
// labelled "Not yet available" / "Coming soon".
const faqs = computed(() => [
    {
        id: 'workspace',
        q: 'How do I sign up and create a workspace?',
        a: [
            'Register with your name, email and a password, and confirm your email address if asked. Then create a workspace from the workspace menu at the top of the sidebar. You become its owner, and it gets its own subdomain (for example acme.<your platform domain>).',
            'You can belong to several workspaces and switch between them from the same menu. Everything you see (mail, domains, keys, webhooks, contacts) belongs to the workspace you are in.',
        ],
        note: 'Owners and admins can invite teammates by email from Settings → Team. Open self-serve signup remains at /join when enabled.',
    },
    {
        id: 'domain',
        q: 'How do I connect a domain?',
        a: [
            'Go to Domains → Add domain and enter the domain you want to send from (for example mail.yourcompany.com). We generate the DNS records you need and show them on the domain page with copy buttons.',
            'If your DNS is hosted on Cloudflare, you can connect it from the domain page and MailDesk can publish the records for you. For any other DNS host, add the records by hand.',
        ],
        link: { route: 'domains', label: 'Open Domains' },
    },
    {
        id: 'dns',
        q: 'What are the SPF, DKIM and DMARC records, and how does verification work?',
        list: [
            'DKIM: a TXT record with a public key that lets receivers confirm the mail really came from you.',
            'SPF: a TXT record listing the servers allowed to send for your domain.',
            'DMARC: a TXT record (we suggest starting with p=none) telling receivers what to do with mail that fails the checks.',
        ],
        a: [
            'Clicking Verify runs real DNS lookups against the records we expect and shows which ones pass. A domain is verified only when all the required records match.',
            'You don’t have to keep clicking: unverified domains are re-checked automatically every hour and verified ones once a day. DNS changes can take a while to spread, so if a record fails right after you add it, wait a bit and try again.',
        ],
    },
    {
        id: 'send',
        q: 'How do I send my first email?',
        a: [
            'In the app: click Compose (or the floating compose button), choose a From address on one of your verified domains, add recipients, a subject and a message, and send. You can attach files (up to 10, 10 MB each) or schedule the email for later. Sent mail appears under Emails and Sent.',
            'From code: create an API key, then call POST /api/v1/emails with the header "Authorization: Bearer <your key>". The API docs have the full request format and examples.',
            'Addresses on your suppression list (unsubscribed, bounced or complained) are skipped automatically.',
        ],
        note: 'Compose needs an active mail provider on your workspace. If it is disabled, contact your platform administrator.',
        link: { route: 'docs', label: 'Read the API docs' },
    },
    {
        id: 'inbox',
        q: 'How do the shared inbox, replies and forwarding work?',
        a: [
            'Mail sent to your mailbox addresses (and catch-all mail for your domains, where your provider is set up to deliver it) lands in Inbox, grouped into conversations. Read and unread state is saved for the whole workspace.',
            'Open a conversation and press Reply (or the r key) to open the reply box, with Cc, Bcc and attachments. Press Forward (or f) to send the message on to someone else. Replies stay in the same thread, and when the customer answers, their reply comes back into that conversation.',
        ],
        note: 'New mail updates the Inbox badge and open list over WebSocket when Reverb is running; otherwise it falls back to polling every few seconds.',
        link: { route: 'inbox', label: 'Open Inbox' },
    },
    {
        id: 'broadcasts',
        q: 'How do broadcasts work?',
        a: [
            'Broadcasts send one message to many people: all your contacts, a segment or a group. Create a broadcast, review it, and send. Sending is queued and throttled to stay within provider limits, and each recipient’s status is tracked on the broadcast page.',
            'Every broadcast email includes an unsubscribe link and one-click unsubscribe headers. Unsubscribed and suppressed contacts are skipped automatically. You can customise the unsubscribe page under Settings → Unsubscribe page.',
        ],
        note: 'Scheduling broadcasts, editing drafts and click tracking are not yet available.',
        link: { route: 'broadcasts', label: 'Open Broadcasts' },
    },
    {
        id: 'webhooks',
        q: 'How do webhooks work (events, signing, testing)?',
        a: [
            'Add an endpoint URL under Webhooks and choose the events you want: email.sent, email.delivered, email.bounced, email.complained, email.received, email.opened or email.clicked. We POST a JSON payload to your URL whenever one of those events happens.',
            'Each request is signed with your endpoint’s secret (shown once, when you create it). The X-MailDesk-Signature header is an HMAC-SHA256 of the raw body. X-MailDesk-Signature-V2 signs the X-MailDesk-Timestamp value plus the body, so you can also reject old, replayed requests. Always compare signatures before trusting a payload.',
            'Use Send test event on the webhook page to send a signed webhook.test event and see the response straight away. Failed deliveries are retried automatically with increasing delays (up to 5 attempts). Workspace owners and admins can rotate the signing secret; the old secret stops working immediately.',
        ],
        note: 'Delivered, bounced, complained, opened and clicked events only arrive when your mail provider reports them. For Resend, subscribe the webhook to email.opened and email.clicked (and enable open/click tracking) in addition to delivery events.',
        link: { route: 'webhooks', label: 'Open Webhooks' },
    },
    {
        id: 'api-keys',
        q: 'What permissions do API keys have, and how do I rotate one?',
        a: [
            'When you create a key, choose Full access (every API endpoint) or Sending access (only POST /api/v1/emails). You can also limit a key to a single sending domain and give it an expiry (7 to 365 days, or never).',
            'The key is shown once, when it is created. To replace a key, use Rotate: you get a new key with the same name, permissions, domain and validity period, and the old key stops working immediately. You can also change a key’s permissions or delete it at any time.',
        ],
        link: { route: 'api-keys', label: 'Open API Keys' },
    },
    {
        id: 'billing',
        q: 'How do billing and plans work?',
        a: [
            'See your plan and usage under Settings → Usage and Settings → Billing. Paid plans are paid in naira (₦) through Monipay’s secure checkout by card or bank transfer: open View plans, choose a plan, and Pay with Monipay takes you straight to checkout. You are brought back to MailDesk when you finish, and your plan is activated once we have confirmed the payment with Monipay.',
            'Plans are prepaid for one billing period at a time and do not renew automatically. To continue, pay again before the period ends; paying for the plan you already have extends it by another period.',
        ],
        note: props.paymentsConfigured
            ? 'Saved cards, downloadable invoices and self-service cancellation are not yet available.'
            : 'Online payments are not switched on for this platform yet, so Pay with Monipay is disabled. Saved cards, downloadable invoices and self-service cancellation are also not yet available.',
        link: { route: 'settings', params: 'billing', label: 'Open Billing' },
    },
    {
        id: 'support',
        q: 'How do I contact support?',
        a: props.supportEmail
            ? [
                  `Email us at ${props.supportEmail} with your workspace name and, for delivery problems, the email ID or message you are asking about.`,
              ]
            : [
                  'Contact your platform administrator or account manager, and include your workspace name and, for delivery problems, the email ID or message you are asking about.',
              ],
        note: 'In-app support chat is coming soon.',
    },
]);

const href = (item) =>
    item.params !== undefined ? route(item.route, item.params) : route(item.route);
</script>

<template>
    <Head title="Help" />

    <AppLayout>
        <PageHeader
            title="Help"
            :description="`Answers to common questions about using ${appName}.`"
        >
            <template #actions>
                <Link :href="route('docs')" class="md-btn-ghost">
                    <BookOpen :size="14" />
                    API docs
                </Link>
                <a
                    v-if="supportEmail"
                    :href="`mailto:${supportEmail}`"
                    class="md-btn-primary"
                >
                    <LifeBuoy :size="14" />
                    Contact support
                </a>
            </template>
        </PageHeader>

        <div class="mx-auto grid max-w-5xl gap-4">
            <!-- What MailDesk is -->
            <section class="md-card p-5">
                <h2 class="text-sm font-medium text-white">
                    What is {{ appName }}?
                </h2>
                <p class="mt-2 text-sm leading-relaxed text-zinc-400">
                    {{ appName }} is a multi-tenant email platform. Each
                    workspace can send email through the API, the Compose
                    window or broadcasts; read and answer customer mail in a
                    shared inbox; verify its own sending domains; and manage
                    contacts, segments and suppressions. Webhooks and a REST
                    API connect it to your own systems.
                </p>
            </section>

            <!-- Getting started -->
            <section class="md-card overflow-hidden">
                <div class="border-b border-zinc-800 px-5 py-3.5">
                    <h2 class="text-sm font-medium text-white">
                        Getting started
                    </h2>
                    <p class="mt-0.5 text-xs text-zinc-500">
                        The same four steps as the “Get started” checklist.
                    </p>
                </div>
                <ol class="divide-y divide-zinc-900">
                    <li
                        v-for="(step, i) in steps"
                        :key="step.key"
                        class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center"
                    >
                        <div class="flex min-w-0 flex-1 items-start gap-3">
                            <span
                                class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-zinc-800 text-xs text-zinc-400"
                                :class="
                                    isDone(step.key)
                                        ? 'bg-emerald-500/15 text-emerald-400'
                                        : ''
                                "
                            >
                                <Check v-if="isDone(step.key)" :size="14" />
                                <template v-else>{{ i + 1 }}</template>
                            </span>
                            <div class="min-w-0">
                                <p class="flex items-center gap-2 text-sm text-white">
                                    <component
                                        :is="step.icon"
                                        :size="14"
                                        class="text-zinc-500"
                                    />
                                    {{ step.title }}
                                </p>
                                <p class="mt-0.5 text-sm text-zinc-400">
                                    {{ step.body }}
                                </p>
                            </div>
                        </div>
                        <Link
                            :href="route(step.route)"
                            class="md-btn-ghost shrink-0 self-start sm:self-auto"
                        >
                            {{ step.cta }}
                        </Link>
                    </li>
                </ol>
            </section>

            <!-- FAQ -->
            <section class="md-card overflow-hidden">
                <div class="border-b border-zinc-800 px-5 py-3.5">
                    <h2 class="text-sm font-medium text-white">
                        Frequently asked questions
                    </h2>
                </div>
                <div class="divide-y divide-zinc-900">
                    <details
                        v-for="item in faqs"
                        :id="`faq-${item.id}`"
                        :key="item.id"
                        class="group"
                    >
                        <summary
                            class="flex cursor-pointer list-none items-center justify-between gap-3 px-5 py-3.5 text-sm text-zinc-200 transition hover:bg-zinc-900 hover:text-white [&::-webkit-details-marker]:hidden"
                        >
                            <span>{{ item.q }}</span>
                            <ChevronDown
                                :size="16"
                                class="shrink-0 text-zinc-500 transition-transform duration-200 group-open:rotate-180"
                            />
                        </summary>
                        <div class="space-y-3 px-5 pb-5 text-sm leading-relaxed text-zinc-400">
                            <ul
                                v-if="item.list"
                                class="list-disc space-y-1 pl-5"
                            >
                                <li v-for="line in item.list" :key="line">
                                    {{ line }}
                                </li>
                            </ul>
                            <p v-for="para in item.a" :key="para">
                                {{ para }}
                            </p>
                            <p
                                v-if="item.note"
                                class="rounded-lg border border-zinc-800 bg-zinc-900 px-3 py-2 text-xs text-zinc-400"
                            >
                                <span class="font-medium text-zinc-300"
                                    >Note:</span
                                >
                                {{ item.note }}
                            </p>
                            <Link
                                v-if="item.link"
                                :href="href(item.link)"
                                class="inline-flex text-sm text-cyan-300 hover:text-cyan-200"
                            >
                                {{ item.link.label }} →
                            </Link>
                        </div>
                    </details>
                </div>
            </section>

            <!-- Support -->
            <section class="md-card flex flex-col gap-3 p-5 sm:flex-row sm:items-center">
                <LifeBuoy :size="18" class="shrink-0 text-cyan-300" />
                <div class="min-w-0 flex-1">
                    <h2 class="text-sm font-medium text-white">Still stuck?</h2>
                    <p class="mt-0.5 text-sm text-zinc-400">
                        <template v-if="supportEmail">
                            Email
                            <a
                                :href="`mailto:${supportEmail}`"
                                class="text-cyan-300 hover:text-cyan-200"
                                >{{ supportEmail }}</a
                            >
                            and we’ll help.
                        </template>
                        <template v-else>
                            Contact your platform administrator. In-app support
                            chat is coming soon.
                        </template>
                        For API details, see the
                        <Link
                            :href="route('docs')"
                            class="text-cyan-300 hover:text-cyan-200"
                            >API docs</Link
                        >.
                    </p>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
