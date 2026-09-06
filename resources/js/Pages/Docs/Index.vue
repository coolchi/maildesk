<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import { useToast } from '@/composables/useToast';
import {
    BookOpen,
    Check,
    ChevronRight,
    Copy,
    KeyRound,
    Search,
    Shield,
    Zap,
} from '@lucide/vue';

const toast = useToast();
const baseUrl = 'http://maildesk.test/api/v1';
const active = ref('introduction');
const copied = ref('');
const navQuery = ref('');
const codeLang = ref('curl');
const contentRef = ref(null);

const navGroups = [
    {
        label: 'Getting started',
        items: [
            { id: 'introduction', label: 'Introduction' },
            { id: 'authentication', label: 'Authentication' },
            { id: 'errors', label: 'Errors & limits' },
            { id: 'idempotency', label: 'Idempotency' },
        ],
    },
    {
        label: 'Emails',
        items: [
            { id: 'send', label: 'Send email' },
            { id: 'batch', label: 'Batch send' },
            { id: 'list', label: 'List emails' },
            { id: 'retrieve', label: 'Retrieve email' },
            { id: 'attachments', label: 'Attachments' },
        ],
    },
    {
        label: 'Inbox',
        items: [
            { id: 'inbox', label: 'Threads' },
            { id: 'reply', label: 'Reply' },
        ],
    },
    {
        label: 'Resources',
        items: [
            { id: 'domains', label: 'Domains' },
            { id: 'audiences', label: 'Audiences' },
            { id: 'templates', label: 'Templates' },
            { id: 'api-keys', label: 'API keys' },
        ],
    },
    {
        label: 'Webhooks',
        items: [
            { id: 'webhooks', label: 'Overview' },
            { id: 'events', label: 'Event catalog' },
            { id: 'verify', label: 'Verify signatures' },
        ],
    },
    {
        label: 'Examples',
        items: [
            { id: 'sdks', label: 'SDKs & snippets' },
            { id: 'quickstart', label: 'Quickstart' },
        ],
    },
];

const filteredNav = computed(() => {
    const q = navQuery.value.trim().toLowerCase();
    if (!q) return navGroups;
    return navGroups
        .map((g) => ({
            ...g,
            items: g.items.filter((i) => i.label.toLowerCase().includes(q)),
        }))
        .filter((g) => g.items.length);
});

const sendParams = [
    {
        name: 'from',
        type: 'string',
        required: true,
        desc: 'Sender address. Use Name <email@domain> or a verified domain address.',
    },
    {
        name: 'to',
        type: 'string | string[]',
        required: true,
        desc: 'One or more recipients. Max 50 per request.',
    },
    {
        name: 'subject',
        type: 'string',
        required: true,
        desc: 'Email subject line.',
    },
    {
        name: 'html',
        type: 'string',
        required: false,
        desc: 'HTML body. Provide html and/or text.',
    },
    {
        name: 'text',
        type: 'string',
        required: false,
        desc: 'Plain-text body. Required if html is omitted.',
    },
    {
        name: 'cc',
        type: 'string | string[]',
        required: false,
        desc: 'Carbon-copy recipients.',
    },
    {
        name: 'bcc',
        type: 'string | string[]',
        required: false,
        desc: 'Blind carbon-copy recipients.',
    },
    {
        name: 'reply_to',
        type: 'string | string[]',
        required: false,
        desc: 'Override Reply-To header.',
    },
    {
        name: 'headers',
        type: 'object',
        required: false,
        desc: 'Custom headers as key/value pairs (e.g. X-Entity-Ref-ID).',
    },
    {
        name: 'attachments',
        type: 'object[]',
        required: false,
        desc: 'Files as base64 content or remote URL. See Attachments.',
    },
    {
        name: 'tags',
        type: 'string[] | object[]',
        required: false,
        desc: 'Labels for filtering in the dashboard and webhooks.',
    },
    {
        name: 'scheduled_at',
        type: 'string',
        required: false,
        desc: 'ISO-8601 datetime to delay send (max 72 hours ahead).',
    },
];

const errorCodes = [
    {
        code: '400',
        name: 'validation_error',
        desc: 'Missing or invalid fields in the request body.',
    },
    {
        code: '401',
        name: 'unauthorized',
        desc: 'Missing or invalid API key.',
    },
    {
        code: '403',
        name: 'forbidden',
        desc: 'Key lacks permission for this domain or action.',
    },
    {
        code: '404',
        name: 'not_found',
        desc: 'Resource UUID does not exist in this workspace.',
    },
    {
        code: '409',
        name: 'conflict',
        desc: 'Idempotency key reused with a different payload.',
    },
    {
        code: '422',
        name: 'unprocessable',
        desc: 'Domain unverified, suppressed recipient, or invalid attachment.',
    },
    {
        code: '429',
        name: 'rate_limited',
        desc: 'Too many requests. Respect Retry-After.',
    },
    {
        code: '500',
        name: 'internal_error',
        desc: 'Unexpected failure. Retry with backoff.',
    },
];

const eventCatalog = [
    {
        name: 'email.sent',
        desc: 'Message accepted and queued for delivery.',
    },
    {
        name: 'email.delivered',
        desc: 'Receiving server accepted the message.',
    },
    {
        name: 'email.delivery_delayed',
        desc: 'Temporary failure; will retry.',
    },
    {
        name: 'email.bounced',
        desc: 'Hard or soft bounce. Soft bounces may retry.',
    },
    {
        name: 'email.complained',
        desc: 'Recipient marked the message as spam.',
    },
    {
        name: 'email.opened',
        desc: 'Open tracked (requires tracking pixel).',
    },
    {
        name: 'email.clicked',
        desc: 'Link click tracked.',
    },
    {
        name: 'email.received',
        desc: 'Inbound message landed in your inbox.',
    },
    {
        name: 'domain.verified',
        desc: 'SPF/DKIM checks passed for a sending domain.',
    },
    {
        name: 'webhook.failed',
        desc: 'Your endpoint returned a non-2xx after retries.',
    },
];

const snippets = {
    curl: `curl -X POST ${baseUrl}/emails \\
  -H "Authorization: Bearer md_xxxxxxxx" \\
  -H "Content-Type: application/json" \\
  -H "Idempotency-Key: welcome-user-42" \\
  -d '{
    "from": "Acme <hello@acme.com>",
    "to": ["user@example.com"],
    "subject": "Welcome to Acme",
    "html": "<h1>Hello</h1><p>Thanks for signing up.</p>",
    "text": "Hello — thanks for signing up.",
    "tags": ["onboarding", "welcome"]
  }'`,
    node: `import MailDesk from '@maildesk/sdk';

const md = new MailDesk('md_xxxxxxxx');

const { data } = await md.emails.send({
  from: 'Acme <hello@acme.com>',
  to: ['user@example.com'],
  subject: 'Welcome to Acme',
  html: '<h1>Hello</h1><p>Thanks for signing up.</p>',
  text: 'Hello — thanks for signing up.',
  tags: ['onboarding', 'welcome'],
});

console.log(data.id);`,
    php: `<?php

use MailDesk\\Client;

$md = new Client('md_xxxxxxxx');

$email = $md->emails->send([
    'from' => 'Acme <hello@acme.com>',
    'to' => ['user@example.com'],
    'subject' => 'Welcome to Acme',
    'html' => '<h1>Hello</h1><p>Thanks for signing up.</p>',
    'text' => 'Hello — thanks for signing up.',
    'tags' => ['onboarding', 'welcome'],
]);

echo $email->id;`,
};

const sectionMeta = {
    introduction: { title: 'Introduction', method: null, path: null },
    authentication: { title: 'Authentication', method: null, path: null },
    errors: { title: 'Errors & rate limits', method: null, path: null },
    idempotency: { title: 'Idempotency', method: null, path: null },
    send: { title: 'Send email', method: 'POST', path: '/emails' },
    batch: { title: 'Batch send', method: 'POST', path: '/emails/batch' },
    list: { title: 'List emails', method: 'GET', path: '/emails' },
    retrieve: { title: 'Retrieve email', method: 'GET', path: '/emails/{id}' },
    attachments: { title: 'Attachments', method: null, path: null },
    inbox: { title: 'List threads', method: 'GET', path: '/inbox/threads' },
    reply: {
        title: 'Reply to thread',
        method: 'POST',
        path: '/inbox/threads/{id}/reply',
    },
    domains: { title: 'Domains', method: null, path: '/domains' },
    audiences: { title: 'Audiences', method: null, path: '/audiences' },
    templates: { title: 'Templates', method: null, path: '/templates' },
    'api-keys': { title: 'API keys', method: null, path: '/api-keys' },
    webhooks: { title: 'Webhooks', method: 'POST', path: '/webhooks' },
    events: { title: 'Event catalog', method: null, path: null },
    verify: { title: 'Verify signatures', method: null, path: null },
    sdks: { title: 'SDKs & snippets', method: null, path: null },
    quickstart: { title: 'Quickstart', method: null, path: null },
};

const meta = computed(() => sectionMeta[active.value] ?? { title: '' });

const codeBlocks = computed(() => {
    const id = active.value;
    const map = {
        send: {
            request: `POST ${baseUrl}/emails
Content-Type: application/json
Authorization: Bearer md_xxxxxxxx

{
  "from": "Acme <hello@acme.com>",
  "to": ["user@example.com"],
  "subject": "Hello from MailDesk",
  "html": "<p>It works.</p>",
  "text": "It works.",
  "reply_to": "support@acme.com",
  "tags": ["onboarding"]
}`,
            response: `{
  "id": "msg_01J8XK2M9Q7R4N6P",
  "from": "Acme <hello@acme.com>",
  "to": ["user@example.com"],
  "created_at": "2026-09-06T11:04:12.000Z"
}`,
        },
        batch: {
            request: `POST ${baseUrl}/emails/batch
Authorization: Bearer md_xxxxxxxx

[
  {
    "from": "hello@acme.com",
    "to": ["a@example.com"],
    "subject": "Hello A",
    "text": "Hi A"
  },
  {
    "from": "hello@acme.com",
    "to": ["b@example.com"],
    "subject": "Hello B",
    "text": "Hi B"
  }
]`,
            response: `{
  "data": [
    { "id": "msg_01…" },
    { "id": "msg_02…" }
  ]
}`,
        },
        list: {
            request: `GET ${baseUrl}/emails?limit=20&cursor=eyJpZCI6Im1zZ18wMSI…
Authorization: Bearer md_xxxxxxxx`,
            response: `{
  "object": "list",
  "data": [
    {
      "id": "msg_01J8XK2M9Q7R4N6P",
      "to": ["user@example.com"],
      "subject": "Hello from MailDesk",
      "last_event": "delivered",
      "created_at": "2026-09-06T11:04:12.000Z"
    }
  ],
  "has_more": true,
  "next_cursor": "eyJpZCI6Im1zZ18wMi…"
}`,
        },
        retrieve: {
            request: `GET ${baseUrl}/emails/msg_01J8XK2M9Q7R4N6P
Authorization: Bearer md_xxxxxxxx`,
            response: `{
  "id": "msg_01J8XK2M9Q7R4N6P",
  "from": "Acme <hello@acme.com>",
  "to": ["user@example.com"],
  "subject": "Hello from MailDesk",
  "html": "<p>It works.</p>",
  "text": "It works.",
  "last_event": "delivered",
  "created_at": "2026-09-06T11:04:12.000Z"
}`,
        },
        attachments: {
            request: `{
  "attachments": [
    {
      "filename": "invoice.pdf",
      "content": "JVBERi0xLjQKJc…"
    },
    {
      "filename": "logo.png",
      "path": "https://cdn.acme.com/logo.png"
    }
  ]
}`,
            response: null,
        },
        inbox: {
            request: `GET ${baseUrl}/inbox/threads?status=open&limit=25
Authorization: Bearer md_xxxxxxxx`,
            response: `{
  "data": [
    {
      "id": "thr_9f2a",
      "subject": "Billing question",
      "from": "customer@example.com",
      "preview": "Can you update our card…",
      "unread": true,
      "updated_at": "2026-09-06T10:12:00.000Z"
    }
  ]
}`,
        },
        reply: {
            request: `POST ${baseUrl}/inbox/threads/thr_9f2a/reply
Authorization: Bearer md_xxxxxxxx

{
  "text": "Thanks — we've updated your card on file.",
  "html": "<p>Thanks — we've updated your card on file.</p>"
}`,
            response: `{
  "id": "msg_reply_01",
  "thread_id": "thr_9f2a",
  "created_at": "2026-09-06T11:20:00.000Z"
}`,
        },
        domains: {
            request: `POST ${baseUrl}/domains
Authorization: Bearer md_xxxxxxxx

{ "name": "acme.com", "region": "us-east-1" }

GET  ${baseUrl}/domains
POST ${baseUrl}/domains/{id}/verify
DELETE ${baseUrl}/domains/{id}`,
            response: `{
  "id": "dom_acme",
  "name": "acme.com",
  "status": "pending",
  "records": [
    { "type": "TXT", "name": "resend._domainkey", "value": "p=MIGf…" },
    { "type": "MX", "name": "send", "value": "feedback-smtp…" }
  ]
}`,
        },
        audiences: {
            request: `POST ${baseUrl}/audiences
{ "name": "Product updates" }

POST ${baseUrl}/audiences/{id}/contacts
{
  "email": "jane@example.com",
  "first_name": "Jane",
  "unsubscribed": false
}

GET ${baseUrl}/audiences/{id}/contacts`,
            response: `{
  "id": "aud_01",
  "name": "Product updates",
  "created_at": "2026-09-01T08:00:00.000Z"
}`,
        },
        templates: {
            request: `POST ${baseUrl}/emails
{
  "from": "hello@acme.com",
  "to": ["user@example.com"],
  "template": {
    "id": "tmpl_welcome",
    "variables": {
      "name": "Ada",
      "cta_url": "https://app.acme.com/start"
    }
  }
}`,
            response: `{
  "id": "msg_01…",
  "template_id": "tmpl_welcome"
}`,
        },
        'api-keys': {
            request: `POST ${baseUrl}/api-keys
{
  "name": "Production",
  "permission": "full_access",
  "domain_id": null
}

GET ${baseUrl}/api-keys
DELETE ${baseUrl}/api-keys/{id}`,
            response: `{
  "id": "key_01",
  "token": "md_xxxxxxxxxxxxxxxx",
  "name": "Production",
  "permission": "full_access"
}`,
        },
        webhooks: {
            request: `POST ${baseUrl}/webhooks
Authorization: Bearer md_xxxxxxxx

{
  "endpoint": "https://app.acme.com/hooks/mail",
  "events": [
    "email.delivered",
    "email.bounced",
    "email.complained",
    "email.received"
  ]
}`,
            response: `{
  "id": "wh_01",
  "endpoint": "https://app.acme.com/hooks/mail",
  "signing_secret": "whsec_…",
  "status": "enabled"
}`,
        },
        events: {
            request: `{
  "type": "email.delivered",
  "created_at": "2026-09-06T11:04:18.000Z",
  "data": {
    "email_id": "msg_01J8XK2M9Q7R4N6P",
    "from": "hello@acme.com",
    "to": ["user@example.com"],
    "subject": "Hello from MailDesk"
  }
}`,
            response: null,
        },
        verify: {
            request: `X-MailDesk-Signature: t=1725615858,v1=5b2c…
X-MailDesk-Timestamp: 1725615858`,
            response: null,
        },
        authentication: {
            request: `Authorization: Bearer md_xxxxxxxx`,
            response: null,
        },
        idempotency: {
            request: `Idempotency-Key: order-4821-receipt`,
            response: null,
        },
        errors: {
            request: `{
  "statusCode": 422,
  "name": "unprocessable",
  "message": "Domain acme.com is not verified."
}`,
            response: null,
        },
        quickstart: {
            request: snippets.curl,
            response: null,
        },
        sdks: {
            request: snippets[codeLang.value],
            response: null,
        },
    };
    return map[id] ?? null;
});

watch(active, async () => {
    await nextTick();
    contentRef.value?.scrollTo?.({ top: 0 });
});

const select = (id) => {
    active.value = id;
};

const copyText = async (text, key = 'main') => {
    try {
        await navigator.clipboard.writeText(text);
        copied.value = key;
        toast.success('Copied to clipboard.');
        window.setTimeout(() => {
            if (copied.value === key) copied.value = '';
        }, 1500);
    } catch {
        toast.error('Could not copy.');
    }
};

const methodClass = (method) => {
    if (method === 'GET') return 'bg-emerald-500/15 text-emerald-300';
    if (method === 'POST') return 'bg-cyan-500/15 text-cyan-300';
    if (method === 'DELETE') return 'bg-rose-500/15 text-rose-300';
    return 'bg-zinc-800 text-zinc-300';
};
</script>

<template>
    <Head title="API Docs" />

    <AppLayout>
        <PageHeader
            title="API Documentation"
            description="HTTP API reference for sending mail, managing domains, inbox, and webhooks."
        >
            <template #actions>
                <Link :href="route('api-keys')" class="md-btn-ghost">
                    <KeyRound :size="14" />
                    API keys
                </Link>
            </template>
        </PageHeader>

        <div class="mb-6 grid gap-3 sm:grid-cols-3">
            <div class="md-card flex items-start gap-3 p-4">
                <div
                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-cyan-400/10 text-cyan-300"
                >
                    <Zap :size="16" />
                </div>
                <div>
                    <p class="text-sm font-medium text-white">Base URL</p>
                    <code class="mt-1 block break-all text-xs text-cyan-300">{{
                        baseUrl
                    }}</code>
                </div>
            </div>
            <div class="md-card flex items-start gap-3 p-4">
                <div
                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-emerald-400/10 text-emerald-300"
                >
                    <Shield :size="16" />
                </div>
                <div>
                    <p class="text-sm font-medium text-white">Auth</p>
                    <p class="mt-1 text-xs text-zinc-400">
                        Bearer token ·
                        <code class="text-zinc-300">md_…</code> keys
                    </p>
                </div>
            </div>
            <div class="md-card flex items-start gap-3 p-4">
                <div
                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-amber-400/10 text-amber-300"
                >
                    <BookOpen :size="16" />
                </div>
                <div>
                    <p class="text-sm font-medium text-white">Version</p>
                    <p class="mt-1 text-xs text-zinc-400">
                        v1 · JSON · UTF-8
                    </p>
                </div>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-[240px_minmax(0,1fr)]">
            <aside class="lg:sticky lg:top-20 lg:self-start">
                <div class="relative mb-3">
                    <Search
                        :size="14"
                        class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-zinc-500"
                    />
                    <input
                        v-model="navQuery"
                        type="search"
                        placeholder="Search docs…"
                        class="md-input pl-9 text-sm"
                    />
                </div>
                <nav class="max-h-[70vh] space-y-4 overflow-y-auto pr-1">
                    <div v-for="group in filteredNav" :key="group.label">
                        <p
                            class="mb-1.5 px-3 text-[10px] font-semibold uppercase tracking-wider text-zinc-600"
                        >
                            {{ group.label }}
                        </p>
                        <button
                            v-for="item in group.items"
                            :key="item.id"
                            type="button"
                            class="flex w-full items-center justify-between rounded-lg px-3 py-1.5 text-left text-sm transition"
                            :class="
                                active === item.id
                                    ? 'bg-cyan-400/10 text-cyan-300'
                                    : 'text-zinc-400 hover:bg-zinc-900 hover:text-zinc-200'
                            "
                            @click="select(item.id)"
                        >
                            <span>{{ item.label }}</span>
                            <ChevronRight
                                v-if="active === item.id"
                                :size="12"
                                class="opacity-70"
                            />
                        </button>
                    </div>
                    <p
                        v-if="!filteredNav.length"
                        class="px-3 text-xs text-zinc-500"
                    >
                        No matching sections.
                    </p>
                </nav>
            </aside>

            <article ref="contentRef" class="md-card min-w-0 p-6 sm:p-8">
                <header class="mb-6 border-b border-zinc-800 pb-5">
                    <div
                        v-if="meta.method"
                        class="mb-3 flex flex-wrap items-center gap-2"
                    >
                        <span
                            class="rounded-md px-2 py-0.5 font-mono text-[11px] font-semibold"
                            :class="methodClass(meta.method)"
                        >
                            {{ meta.method }}
                        </span>
                        <code class="text-sm text-zinc-300"
                            >{{ baseUrl
                            }}{{ meta.path }}</code
                        >
                    </div>
                    <h2 class="text-xl font-semibold tracking-tight text-white">
                        {{ meta.title }}
                    </h2>
                </header>

                <!-- Introduction -->
                <div v-if="active === 'introduction'" class="space-y-5 text-sm leading-relaxed text-zinc-400">
                    <p>
                        MailDesk exposes a Resend-compatible HTTP API for
                        transactional and marketing email, inbound inbox,
                        domains, and webhooks. All endpoints accept and return
                        JSON unless noted.
                    </p>
                    <div class="rounded-xl border border-zinc-800 bg-zinc-950/60 p-4">
                        <p class="text-xs font-medium uppercase tracking-wide text-zinc-500">
                            Conventions
                        </p>
                        <ul class="mt-3 space-y-2 text-zinc-300">
                            <li>
                                · Timestamps are ISO-8601 UTC (
                                <code class="text-cyan-300">…Z</code>).
                            </li>
                            <li>
                                · IDs are opaque strings (
                                <code class="text-cyan-300">msg_</code>,
                                <code class="text-cyan-300">dom_</code>,
                                <code class="text-cyan-300">wh_</code>).
                            </li>
                            <li>
                                · Pagination uses cursor tokens —
                                <code class="text-cyan-300">limit</code> +
                                <code class="text-cyan-300">cursor</code>.
                            </li>
                            <li>
                                · Send from verified domains only; unverified
                                sends return
                                <code class="text-cyan-300">422</code>.
                            </li>
                        </ul>
                    </div>
                    <p>
                        Prefer starting with
                        <button
                            type="button"
                            class="text-cyan-300 hover:underline"
                            @click="select('quickstart')"
                        >
                            Quickstart
                        </button>
                        or create a key under
                        <Link
                            :href="route('api-keys')"
                            class="text-cyan-300 hover:underline"
                            >API Keys</Link
                        >.
                    </p>
                </div>

                <!-- Authentication -->
                <div
                    v-else-if="active === 'authentication'"
                    class="space-y-5 text-sm leading-relaxed text-zinc-400"
                >
                    <p>
                        Authenticate every request with an API key in the
                        <code class="text-zinc-300">Authorization</code>
                        header. Keys are created in the dashboard and shown once.
                    </p>
                    <pre
                        class="overflow-x-auto rounded-lg border border-zinc-800 bg-black/50 p-4 text-[12px] text-zinc-300"
                    >Authorization: Bearer md_xxxxxxxx</pre>
                    <div class="overflow-x-auto rounded-xl border border-zinc-800">
                        <table class="min-w-full text-left text-sm">
                            <thead
                                class="border-b border-zinc-800 text-xs uppercase tracking-wide text-zinc-500"
                            >
                                <tr>
                                    <th class="px-4 py-2.5 font-medium">
                                        Permission
                                    </th>
                                    <th class="px-4 py-2.5 font-medium">
                                        Access
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-900 text-zinc-300">
                                <tr>
                                    <td class="px-4 py-3 font-mono text-xs">
                                        full_access
                                    </td>
                                    <td class="px-4 py-3 text-zinc-400">
                                        Send, read, manage domains, webhooks,
                                        keys
                                    </td>
                                </tr>
                                <tr>
                                    <td class="px-4 py-3 font-mono text-xs">
                                        sending_access
                                    </td>
                                    <td class="px-4 py-3 text-zinc-400">
                                        Send and list emails only (optional
                                        domain scope)
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p class="text-zinc-500">
                        Never embed keys in client-side apps. Rotate compromised
                        keys immediately from the API Keys page.
                    </p>
                </div>

                <!-- Errors -->
                <div
                    v-else-if="active === 'errors'"
                    class="space-y-5 text-sm leading-relaxed text-zinc-400"
                >
                    <p>
                        Errors return a JSON body with
                        <code class="text-zinc-300">statusCode</code>,
                        <code class="text-zinc-300">name</code>, and
                        <code class="text-zinc-300">message</code>. Rate limits
                        include
                        <code class="text-zinc-300">Retry-After</code>
                        (seconds).
                    </p>
                    <div class="overflow-x-auto rounded-xl border border-zinc-800">
                        <table class="min-w-full text-left text-sm">
                            <thead
                                class="border-b border-zinc-800 text-xs uppercase tracking-wide text-zinc-500"
                            >
                                <tr>
                                    <th class="px-4 py-2.5 font-medium">
                                        Status
                                    </th>
                                    <th class="px-4 py-2.5 font-medium">Name</th>
                                    <th class="px-4 py-2.5 font-medium">
                                        When
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-900">
                                <tr
                                    v-for="err in errorCodes"
                                    :key="err.code"
                                >
                                    <td
                                        class="px-4 py-3 font-mono text-xs text-cyan-300"
                                    >
                                        {{ err.code }}
                                    </td>
                                    <td
                                        class="px-4 py-3 font-mono text-xs text-zinc-300"
                                    >
                                        {{ err.name }}
                                    </td>
                                    <td class="px-4 py-3 text-zinc-400">
                                        {{ err.desc }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="rounded-xl border border-zinc-800 bg-zinc-950/60 p-4">
                        <p class="text-xs font-medium text-zinc-300">
                            Default limits (Pro)
                        </p>
                        <ul class="mt-2 space-y-1 text-zinc-400">
                            <li>· 10 requests / second per API key</li>
                            <li>· 100 emails / second burst (batch counts per item)</li>
                            <li>· 40 MB max request body (attachments included)</li>
                        </ul>
                    </div>
                    <div>
                        <div class="mb-2 flex items-center justify-between">
                            <p class="text-xs font-medium uppercase tracking-wide text-zinc-500">
                                Example error
                            </p>
                            <button
                                type="button"
                                class="md-btn-ghost"
                                @click="copyText(codeBlocks.request, 'err')"
                            >
                                <Check
                                    v-if="copied === 'err'"
                                    :size="14"
                                    class="text-emerald-400"
                                />
                                <Copy v-else :size="14" />
                                Copy
                            </button>
                        </div>
                        <pre
                            class="overflow-x-auto rounded-lg border border-zinc-800 bg-black/50 p-4 text-[12px] text-zinc-300"
                        >{{ codeBlocks.request }}</pre>
                    </div>
                </div>

                <!-- Idempotency -->
                <div
                    v-else-if="active === 'idempotency'"
                    class="space-y-5 text-sm leading-relaxed text-zinc-400"
                >
                    <p>
                        Pass
                        <code class="text-zinc-300">Idempotency-Key</code>
                        on
                        <code class="text-zinc-300">POST</code>
                        requests to safely retry without duplicating sends. Keys
                        are unique per workspace for 24 hours.
                    </p>
                    <pre
                        class="overflow-x-auto rounded-lg border border-zinc-800 bg-black/50 p-4 text-[12px] text-zinc-300"
                    >Idempotency-Key: order-4821-receipt</pre>
                    <ul class="list-disc space-y-2 pl-5">
                        <li>
                            Same key + same body → original
                            <code class="text-zinc-300">200/201</code>
                            response replayed.
                        </li>
                        <li>
                            Same key + different body →
                            <code class="text-zinc-300">409 conflict</code>.
                        </li>
                        <li>
                            Use stable business IDs (order, invoice, user
                            invite).
                        </li>
                    </ul>
                </div>

                <!-- Send email (with params) -->
                <div
                    v-else-if="active === 'send'"
                    class="space-y-6 text-sm leading-relaxed text-zinc-400"
                >
                    <p>
                        Queue a single transactional or marketing email. At
                        least one of
                        <code class="text-zinc-300">html</code>
                        or
                        <code class="text-zinc-300">text</code>
                        is required.
                    </p>

                    <div>
                        <p
                            class="mb-2 text-xs font-medium uppercase tracking-wide text-zinc-500"
                        >
                            Body parameters
                        </p>
                        <div
                            class="overflow-x-auto rounded-xl border border-zinc-800"
                        >
                            <table class="min-w-full text-left text-sm">
                                <thead
                                    class="border-b border-zinc-800 text-xs uppercase tracking-wide text-zinc-500"
                                >
                                    <tr>
                                        <th class="px-4 py-2.5 font-medium">
                                            Field
                                        </th>
                                        <th class="px-4 py-2.5 font-medium">
                                            Type
                                        </th>
                                        <th class="px-4 py-2.5 font-medium">
                                            Description
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-zinc-900">
                                    <tr
                                        v-for="p in sendParams"
                                        :key="p.name"
                                    >
                                        <td
                                            class="whitespace-nowrap px-4 py-3 font-mono text-xs text-cyan-300"
                                        >
                                            {{ p.name }}
                                            <span
                                                v-if="p.required"
                                                class="ml-1 text-[10px] text-rose-400"
                                                >required</span
                                            >
                                        </td>
                                        <td
                                            class="whitespace-nowrap px-4 py-3 font-mono text-[11px] text-zinc-500"
                                        >
                                            {{ p.type }}
                                        </td>
                                        <td class="px-4 py-3 text-zinc-400">
                                            {{ p.desc }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div>
                        <div class="mb-2 flex items-center justify-between gap-2">
                            <p
                                class="text-xs font-medium uppercase tracking-wide text-zinc-500"
                            >
                                Request
                            </p>
                            <button
                                type="button"
                                class="md-btn-ghost"
                                @click="copyText(codeBlocks.request, 'req')"
                            >
                                <Check
                                    v-if="copied === 'req'"
                                    :size="14"
                                    class="text-emerald-400"
                                />
                                <Copy v-else :size="14" />
                                Copy
                            </button>
                        </div>
                        <pre
                            class="overflow-x-auto rounded-lg border border-zinc-800 bg-black/50 p-4 text-[12px] leading-relaxed text-zinc-300"
                        >{{ codeBlocks.request }}</pre>
                    </div>

                    <div>
                        <div class="mb-2 flex items-center justify-between gap-2">
                            <p
                                class="text-xs font-medium uppercase tracking-wide text-zinc-500"
                            >
                                Response
                                <span class="ml-2 text-emerald-400">201</span>
                            </p>
                            <button
                                type="button"
                                class="md-btn-ghost"
                                @click="copyText(codeBlocks.response, 'res')"
                            >
                                <Check
                                    v-if="copied === 'res'"
                                    :size="14"
                                    class="text-emerald-400"
                                />
                                <Copy v-else :size="14" />
                                Copy
                            </button>
                        </div>
                        <pre
                            class="overflow-x-auto rounded-lg border border-zinc-800 bg-black/50 p-4 text-[12px] leading-relaxed text-zinc-300"
                        >{{ codeBlocks.response }}</pre>
                    </div>
                </div>

                <!-- Events catalog -->
                <div
                    v-else-if="active === 'events'"
                    class="space-y-5 text-sm leading-relaxed text-zinc-400"
                >
                    <p>
                        Subscribe to these events when creating a webhook.
                        Delivery is at-least-once; use
                        <code class="text-zinc-300">email_id</code>
                        +
                        <code class="text-zinc-300">type</code>
                        for deduplication.
                    </p>
                    <div class="overflow-x-auto rounded-xl border border-zinc-800">
                        <table class="min-w-full text-left text-sm">
                            <thead
                                class="border-b border-zinc-800 text-xs uppercase tracking-wide text-zinc-500"
                            >
                                <tr>
                                    <th class="px-4 py-2.5 font-medium">
                                        Event
                                    </th>
                                    <th class="px-4 py-2.5 font-medium">
                                        Description
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-900">
                                <tr
                                    v-for="ev in eventCatalog"
                                    :key="ev.name"
                                >
                                    <td
                                        class="whitespace-nowrap px-4 py-3 font-mono text-xs text-cyan-300"
                                    >
                                        {{ ev.name }}
                                    </td>
                                    <td class="px-4 py-3 text-zinc-400">
                                        {{ ev.desc }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div>
                        <div class="mb-2 flex items-center justify-between">
                            <p
                                class="text-xs font-medium uppercase tracking-wide text-zinc-500"
                            >
                                Sample payload
                            </p>
                            <button
                                type="button"
                                class="md-btn-ghost"
                                @click="copyText(codeBlocks.request, 'ev')"
                            >
                                <Check
                                    v-if="copied === 'ev'"
                                    :size="14"
                                    class="text-emerald-400"
                                />
                                <Copy v-else :size="14" />
                                Copy
                            </button>
                        </div>
                        <pre
                            class="overflow-x-auto rounded-lg border border-zinc-800 bg-black/50 p-4 text-[12px] text-zinc-300"
                        >{{ codeBlocks.request }}</pre>
                    </div>
                </div>

                <!-- Verify signatures -->
                <div
                    v-else-if="active === 'verify'"
                    class="space-y-5 text-sm leading-relaxed text-zinc-400"
                >
                    <p>
                        Every webhook POST includes a signature header. Compute
                        an HMAC-SHA256 over
                        <code class="text-zinc-300"
                            >{timestamp}.{rawBody}</code
                        >
                        using your endpoint signing secret and compare to
                        <code class="text-zinc-300">v1</code>.
                    </p>
                    <ol class="list-decimal space-y-2 pl-5">
                        <li>Read <code class="text-zinc-300">X-MailDesk-Timestamp</code> and <code class="text-zinc-300">X-MailDesk-Signature</code>.</li>
                        <li>Reject if timestamp is older than 5 minutes (replay protection).</li>
                        <li>
                            Compute
                            <code class="text-zinc-300"
                                >HMAC_SHA256(secret, `${t}.${body}`)</code
                            >
                            and constant-time compare to
                            <code class="text-zinc-300">v1</code>.
                        </li>
                        <li>Return <code class="text-zinc-300">2xx</code> within 10s. Failures retry with exponential backoff for 24h.</li>
                    </ol>
                    <pre
                        class="overflow-x-auto rounded-lg border border-zinc-800 bg-black/50 p-4 text-[12px] text-zinc-300"
                    >{{ codeBlocks.request }}</pre>
                </div>

                <!-- SDKs -->
                <div
                    v-else-if="active === 'sdks'"
                    class="space-y-5 text-sm leading-relaxed text-zinc-400"
                >
                    <p>
                        Official SDKs wrap the HTTP API. Snippets below send the
                        same welcome email.
                    </p>
                    <div class="inline-flex rounded-full border border-zinc-800 bg-zinc-950 p-1">
                        <button
                            v-for="lang in ['curl', 'node', 'php']"
                            :key="lang"
                            type="button"
                            class="rounded-full px-3 py-1 text-xs font-medium capitalize transition"
                            :class="
                                codeLang === lang
                                    ? 'bg-cyan-400/15 text-cyan-300'
                                    : 'text-zinc-500 hover:text-zinc-300'
                            "
                            @click="codeLang = lang"
                        >
                            {{ lang === 'node' ? 'Node.js' : lang === 'php' ? 'PHP' : 'cURL' }}
                        </button>
                    </div>
                    <div>
                        <div class="mb-2 flex justify-end">
                            <button
                                type="button"
                                class="md-btn-ghost"
                                @click="copyText(snippets[codeLang], 'sdk')"
                            >
                                <Check
                                    v-if="copied === 'sdk'"
                                    :size="14"
                                    class="text-emerald-400"
                                />
                                <Copy v-else :size="14" />
                                Copy
                            </button>
                        </div>
                        <pre
                            class="overflow-x-auto rounded-lg border border-zinc-800 bg-black/50 p-4 text-[12px] leading-relaxed text-zinc-300"
                        >{{ snippets[codeLang] }}</pre>
                    </div>
                </div>

                <!-- Generic endpoint sections -->
                <div v-else class="space-y-6 text-sm leading-relaxed text-zinc-400">
                    <p v-if="active === 'batch'">
                        Send up to 100 messages in one request. Each item uses
                        the same schema as
                        <button
                            type="button"
                            class="text-cyan-300 hover:underline"
                            @click="select('send')"
                        >
                            Send email
                        </button>. Partial success returns per-item results.
                    </p>
                    <p v-else-if="active === 'list'">
                        Returns outbound messages newest-first. Filter with
                        <code class="text-zinc-300">?to=</code>,
                        <code class="text-zinc-300">?subject=</code>, and
                        <code class="text-zinc-300">?tag=</code>. Use
                        <code class="text-zinc-300">cursor</code>
                        from the previous page for the next set.
                    </p>
                    <p v-else-if="active === 'retrieve'">
                        Fetch a single message including HTML/text bodies and
                        the latest delivery event.
                    </p>
                    <p v-else-if="active === 'attachments'">
                        Attach files via base64
                        <code class="text-zinc-300">content</code>
                        or a publicly reachable
                        <code class="text-zinc-300">path</code>
                        URL. Max 40 MB combined per message. Inline images can
                        use
                        <code class="text-zinc-300">content_id</code>
                        referenced from HTML
                        <code class="text-zinc-300">cid:</code>.
                    </p>
                    <p v-else-if="active === 'inbox'">
                        List inbound conversation threads for your receiving
                        domains. Supports
                        <code class="text-zinc-300">status=open|archived</code>
                        and full-text
                        <code class="text-zinc-300">q</code>.
                    </p>
                    <p v-else-if="active === 'reply'">
                        Reply in-thread. MailDesk sets
                        <code class="text-zinc-300">In-Reply-To</code>
                        /
                        <code class="text-zinc-300">References</code>
                        automatically so clients keep threading.
                    </p>
                    <p v-else-if="active === 'domains'">
                        Register a sending domain, add DNS records, then call
                        verify. Only verified domains can be used in
                        <code class="text-zinc-300">from</code>.
                    </p>
                    <p v-else-if="active === 'audiences'">
                        Manage marketing lists and contacts. Unsubscribed
                        contacts are suppressed automatically on send.
                    </p>
                    <p v-else-if="active === 'templates'">
                        Reference a saved template and pass variables. Template
                        HTML uses
                        <code class="text-zinc-300">&#123;&#123;variable&#125;&#125;</code>
                        placeholders.
                    </p>
                    <p v-else-if="active === 'api-keys'">
                        Create, list, and revoke keys programmatically. The
                        plaintext
                        <code class="text-zinc-300">token</code>
                        is only returned on create.
                    </p>
                    <p v-else-if="active === 'webhooks'">
                        Register an HTTPS endpoint to receive delivery and
                        inbound events. Store the
                        <code class="text-zinc-300">signing_secret</code>
                        securely — it is shown once.
                    </p>
                    <p v-else-if="active === 'quickstart'">
                        Copy this request, replace the API key and
                        <code class="text-zinc-300">from</code>
                        address with a verified domain, then send.
                    </p>

                    <div v-if="codeBlocks?.request">
                        <div class="mb-2 flex items-center justify-between gap-2">
                            <p
                                class="text-xs font-medium uppercase tracking-wide text-zinc-500"
                            >
                                {{
                                    codeBlocks.response
                                        ? 'Request'
                                        : 'Example'
                                }}
                            </p>
                            <button
                                type="button"
                                class="md-btn-ghost"
                                @click="copyText(codeBlocks.request, 'req')"
                            >
                                <Check
                                    v-if="copied === 'req'"
                                    :size="14"
                                    class="text-emerald-400"
                                />
                                <Copy v-else :size="14" />
                                Copy
                            </button>
                        </div>
                        <pre
                            class="overflow-x-auto rounded-lg border border-zinc-800 bg-black/50 p-4 text-[12px] leading-relaxed text-zinc-300"
                        >{{ codeBlocks.request }}</pre>
                    </div>

                    <div v-if="codeBlocks?.response">
                        <div class="mb-2 flex items-center justify-between gap-2">
                            <p
                                class="text-xs font-medium uppercase tracking-wide text-zinc-500"
                            >
                                Response
                            </p>
                            <button
                                type="button"
                                class="md-btn-ghost"
                                @click="copyText(codeBlocks.response, 'res')"
                            >
                                <Check
                                    v-if="copied === 'res'"
                                    :size="14"
                                    class="text-emerald-400"
                                />
                                <Copy v-else :size="14" />
                                Copy
                            </button>
                        </div>
                        <pre
                            class="overflow-x-auto rounded-lg border border-zinc-800 bg-black/50 p-4 text-[12px] leading-relaxed text-zinc-300"
                        >{{ codeBlocks.response }}</pre>
                    </div>
                </div>
            </article>
        </div>
    </AppLayout>
</template>
