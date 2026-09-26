<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import { useToast } from '@/composables/useToast';
import {
    DOCS_EXPORT_FILENAME,
    buildDocsMarkdown,
    downloadText,
    htmlToMarkdown,
} from '@/lib/docsMarkdown';
import {
    BookOpen,
    Check,
    ChevronRight,
    Copy,
    Download,
    KeyRound,
    Search,
    Shield,
    Zap,
} from '@lucide/vue';

// Every endpoint, limit and format on this page mirrors routes/api.php and
// the API controllers. Keep them in sync (tests/Feature/DocsPageTest.php).
const props = defineProps({
    apiBaseUrl: { type: String, default: '/api/v1' },
    rateLimit: { type: Number, default: 120 },
    webhookEvents: { type: Array, default: () => [] },
    webhookRetry: {
        type: Object,
        default: () => ({ attempts: 5, backoff: [30, 120, 600, 1800], timeout: 5 }),
    },
});

const toast = useToast();
const baseUrl = computed(() => props.apiBaseUrl);
const active = ref('introduction');
const copied = ref('');
const navQuery = ref('');
const contentRef = ref(null);
const docBodyRef = ref(null);
const exporting = ref(false);

const navGroups = [
    {
        label: 'Getting started',
        items: [
            { id: 'introduction', label: 'Introduction' },
            { id: 'authentication', label: 'Authentication & keys' },
            { id: 'errors', label: 'Errors & rate limits' },
            { id: 'pagination', label: 'Pagination' },
        ],
    },
    {
        label: 'Emails',
        items: [
            { id: 'send', label: 'Send email' },
            { id: 'list', label: 'List emails' },
            { id: 'retrieve', label: 'Retrieve email' },
        ],
    },
    {
        label: 'Inbox',
        items: [
            { id: 'threads', label: 'List threads' },
            { id: 'thread', label: 'Retrieve thread' },
        ],
    },
    {
        label: 'Domains',
        items: [
            { id: 'domains-list', label: 'List domains' },
            { id: 'domains-create', label: 'Add domain' },
        ],
    },
    {
        label: 'Webhooks',
        items: [
            { id: 'webhooks', label: 'Overview & events' },
            { id: 'verify', label: 'Verify signatures' },
            { id: 'retries', label: 'Delivery & retries' },
        ],
    },
    {
        label: 'Provider endpoints',
        items: [
            { id: 'provider-events', label: 'Delivery events' },
            { id: 'provider-inbound', label: 'Inbound mail' },
        ],
    },
    {
        label: 'Examples',
        items: [{ id: 'quickstart', label: 'Quickstart' }],
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

const sectionMeta = {
    introduction: { title: 'Introduction' },
    authentication: { title: 'Authentication & API keys' },
    errors: { title: 'Errors & rate limits' },
    pagination: { title: 'Pagination' },
    send: { title: 'Send email', method: 'POST', path: '/emails' },
    list: { title: 'List emails', method: 'GET', path: '/emails' },
    retrieve: { title: 'Retrieve email', method: 'GET', path: '/emails/{uuid}' },
    threads: { title: 'List threads', method: 'GET', path: '/inbox/threads' },
    thread: { title: 'Retrieve thread', method: 'GET', path: '/inbox/threads/{thread}' },
    'domains-list': { title: 'List domains', method: 'GET', path: '/domains' },
    'domains-create': { title: 'Add domain', method: 'POST', path: '/domains' },
    webhooks: { title: 'Webhooks' },
    verify: { title: 'Verify webhook signatures' },
    retries: { title: 'Webhook delivery & retries' },
    'provider-events': { title: 'Provider delivery events', method: 'POST', path: '/events/{driver}' },
    'provider-inbound': { title: 'Provider inbound mail', method: 'POST', path: '/inbound/{driver}' },
    quickstart: { title: 'Quickstart' },
};

const meta = computed(() => sectionMeta[active.value] ?? { title: '' });

const sendParams = [
    { name: 'from', type: 'string | object', required: true, desc: 'Sender: "hello@acme.com", "Acme <hello@acme.com>" or {"email": "...", "name": "..."}. The domain must be verified in this workspace, and match the key\'s domain scope if it has one.' },
    { name: 'to', type: 'string | array', required: true, desc: 'Recipients: a comma-separated string, an array of addresses, or an array of {"email", "name"} objects.' },
    { name: 'subject', type: 'string', required: true, desc: 'Subject line, up to 998 characters.' },
    { name: 'html', type: 'string', required: false, desc: 'HTML body.' },
    { name: 'text', type: 'string', required: false, desc: 'Plain-text body. Send html, text or both.' },
    { name: 'cc', type: 'string | array', required: false, desc: 'Carbon-copy recipients (same formats as to).' },
    { name: 'bcc', type: 'string | array', required: false, desc: 'Blind carbon-copy recipients. Never returned by the API.' },
    { name: 'reply_to', type: 'string | array', required: false, desc: 'Reply-To address(es).' },
    { name: 'tags', type: 'array', required: false, desc: 'Labels stored on the message (strings are filterable on the Metrics page).' },
    { name: 'headers', type: 'object', required: false, desc: 'Extra headers as {"Header-Name": "value"}.' },
    { name: 'signature', type: 'boolean', required: false, desc: 'Append the sender\'s signature. Defaults to the workspace "add to API emails" setting.' },
];

const statusCodes = computed(() => [
    { code: '200 / 201', desc: 'Success. POST /emails returns 201 when the provider accepted the message; POST /domains returns 201.' },
    { code: '401', desc: 'Missing, invalid, expired or revoked API key.' },
    { code: '403', desc: 'The key is not allowed to call this endpoint (sending-only key) or to send from this domain (domain-scoped key).' },
    { code: '404', desc: 'The email or thread does not exist in this workspace.' },
    { code: '422', desc: 'Validation failed (including an unverified sender domain), or the send was attempted but failed / was suppressed; see status in the body.' },
    { code: '429', desc: `More than ${props.rateLimit} requests in a minute with this key. Wait Retry-After seconds.` },
    { code: '500', desc: 'Unexpected server error. Retry with backoff.' },
]);

const retryDelays = computed(() =>
    props.webhookRetry.backoff
        .map((s) => (s >= 60 ? `${s / 60} min` : `${s} s`))
        .join(', '),
);

const code = computed(() => ({
    auth: `Authorization: Bearer md_xxxxxxxxxxxxxxxxxxxxxxxx
Accept: application/json`,
    errors: `// 401 / 403 / 404 / 429
{ "message": "This API key can only send from acme.com." }

// 422 validation error
{
  "message": "The sender domain gmail.com is not verified for this workspace.",
  "errors": {
    "from": ["The sender domain gmail.com is not verified for this workspace."]
  }
}`,
    rateHeaders: `HTTP/1.1 429 Too Many Requests
Retry-After: 42
X-RateLimit-Limit: ${props.rateLimit}
X-RateLimit-Remaining: 0
X-RateLimit-Reset: 1790000000`,
    pagination: `{
  "current_page": 1,
  "data": [ ... ],
  "first_page_url": "${baseUrl.value}/emails?page=1",
  "from": 1,
  "last_page": 4,
  "last_page_url": "${baseUrl.value}/emails?page=4",
  "links": [ ... ],
  "next_page_url": "${baseUrl.value}/emails?page=2",
  "path": "${baseUrl.value}/emails",
  "per_page": 25,
  "prev_page_url": null,
  "to": 25,
  "total": 87
}`,
    sendRequest: `curl -X POST ${baseUrl.value}/emails \\
  -H "Authorization: Bearer md_xxxxxxxx" \\
  -H "Content-Type: application/json" \\
  -H "Accept: application/json" \\
  -d '{
    "from": "Acme <hello@acme.com>",
    "to": ["user@example.com"],
    "subject": "Hello from MailDesk",
    "html": "<p>It works.</p>",
    "text": "It works.",
    "reply_to": "support@acme.com",
    "tags": ["onboarding"]
  }'`,
    sendResponse: `{
  "id": "9d1c6f3e-6c1b-4f0e-9a51-2f0f5b8a7c11",
  "status": "sent",
  "provider": "resend",
  "provider_message_id": "4ef9a417-02e9-4d39-ad75-9611e0fcc33c",
  "created_at": "2026-09-26T10:04:12.000000Z"
}`,
    sendFailed: `// 422: the provider rejected it, or a recipient is suppressed
{
  "id": "9d1c6f3e-...",
  "status": "failed",          // or "suppressed"
  "provider": "resend",
  "provider_message_id": null,
  "created_at": "2026-09-26T10:04:12.000000Z"
}`,
    list: `curl "${baseUrl.value}/emails?per_page=50&page=2" \\
  -H "Authorization: Bearer md_xxxxxxxx"`,
    listItem: `{
  "id": 1042,
  "uuid": "9d1c6f3e-6c1b-4f0e-9a51-2f0f5b8a7c11",
  "thread_id": 311,
  "direction": "outbound",
  "status": "delivered",
  "provider": "resend",
  "provider_message_id": "4ef9a417-...",
  "from_email": "hello@acme.com",
  "from_name": "Acme",
  "to": ["user@example.com"],
  "cc": null,
  "reply_to": null,
  "subject": "Hello from MailDesk",
  "text_body": "It works.",
  "html_body": "<p>It works.</p>",
  "tags": ["onboarding"],
  "sent_at": "2026-09-26T10:04:12.000000Z",
  "created_at": "2026-09-26T10:04:12.000000Z",
  ...
}`,
    retrieve: `{
  "id": "9d1c6f3e-6c1b-4f0e-9a51-2f0f5b8a7c11",
  "direction": "outbound",
  "status": "delivered",
  "from": { "email": "hello@acme.com", "name": "Acme" },
  "to": ["user@example.com"],
  "cc": null,
  "subject": "Hello from MailDesk",
  "html": "<p>It works.</p>",
  "text": "It works.",
  "provider": "resend",
  "provider_message_id": "4ef9a417-...",
  "created_at": "2026-09-26T10:04:12.000000Z",
  "sent_at": "2026-09-26T10:04:12.000000Z"
}`,
    threads: `curl "${baseUrl.value}/inbox/threads?per_page=25" \\
  -H "Authorization: Bearer md_xxxxxxxx"

// each item in "data"
{
  "id": 311,
  "mailbox_id": 4,
  "subject": "Billing question",
  "snippet": "Can you update our card…",
  "is_read": false,
  "message_count": 3,
  "last_message_at": "2026-09-26T09:12:00.000000Z",
  "messages": [ { ...latest message... } ]
}`,
    thread: `curl ${baseUrl.value}/inbox/threads/311 \\
  -H "Authorization: Bearer md_xxxxxxxx"

// the thread object with "messages": every message, oldest first`,
    domainsList: `curl ${baseUrl.value}/domains -H "Authorization: Bearer md_xxxxxxxx"

[
  {
    "id": 7,
    "name": "acme.com",
    "status": "verified",
    "provider": "resend",
    "dns_records": [ { "type": "TXT", "name": "...", "value": "..." } ],
    "verified_at": "2026-09-20T08:00:00.000000Z",
    "created_at": "2026-09-19T17:30:00.000000Z"
  }
]`,
    domainsCreate: `curl -X POST ${baseUrl.value}/domains \\
  -H "Authorization: Bearer md_xxxxxxxx" \\
  -H "Content-Type: application/json" \\
  -d '{ "name": "mail.acme.com" }'

// 201
{
  "id": 8,
  "name": "mail.acme.com",
  "status": "pending",
  "provider": "resend",
  "dns_records": [ ... ],
  "created_at": "2026-09-26T10:10:00.000000Z"
}`,
    webhookHeaders: `POST /your/endpoint HTTP/1.1
Content-Type: application/json
User-Agent: MailDesk-Webhooks/1.0
X-MailDesk-Event: email.delivered
X-MailDesk-Delivery: 5831
X-MailDesk-Signature: 3f1c…   (hex HMAC-SHA256 of the raw body)
X-MailDesk-Timestamp: 1790000000
X-MailDesk-Signature-V2: a9e0…   (hex HMAC-SHA256 of "{timestamp}.{raw body}")`,
    webhookPayload: `{
  "event": "email.delivered",
  "data": {
    "id": "9d1c6f3e-6c1b-4f0e-9a51-2f0f5b8a7c11",
    "status": "delivered",
    "to": ["user@example.com"],
    "subject": "Hello from MailDesk",
    "occurred_at": "2026-09-26T10:04:18+00:00",
    "bounce_type": null,
    "reason": null
  },
  "sent_at": "2026-09-26T10:04:19+00:00"
}`,
    verifyNode: `import crypto from 'node:crypto';

// rawBody: the exact bytes received (do not re-serialize the JSON)
function verify(rawBody, headers, secret) {
  const ts = headers['x-maildesk-timestamp'];
  const sig = headers['x-maildesk-signature-v2'];
  if (!ts || !sig || Math.abs(Date.now() / 1000 - Number(ts)) > 300) return false;

  const expected = crypto
    .createHmac('sha256', secret)          // secret includes the whsec_ prefix
    .update(\`\${ts}.\${rawBody}\`)
    .digest('hex');

  return sig.length === expected.length &&
    crypto.timingSafeEqual(Buffer.from(sig), Buffer.from(expected));
}`,
    verifyPhp: `$raw = file_get_contents('php://input');
$ts  = $_SERVER['HTTP_X_MAILDESK_TIMESTAMP'] ?? '';
$sig = $_SERVER['HTTP_X_MAILDESK_SIGNATURE_V2'] ?? '';

$fresh = ctype_digit($ts) && abs(time() - (int) $ts) <= 300;
$expected = hash_hmac('sha256', $ts.'.'.$raw, $secret); // secret = "whsec_..."

if (! $fresh || ! hash_equals($expected, $sig)) {
    http_response_code(401);
    exit;
}

// Legacy check (no timestamp): hash_equals(hash_hmac('sha256', $raw, $secret),
//                                          $_SERVER['HTTP_X_MAILDESK_SIGNATURE'])`,
    providerEvents: `POST ${baseUrl.value}/events/resend
svix-id: msg_2abc…
svix-timestamp: 1790000000
svix-signature: v1,base64…

{ "type": "email.delivered", "created_at": "…", "data": { "email_id": "…", "to": ["…"] } }

// 200 {"status": "processed", "id": "<uuid>", "message_status": "delivered", "suppressed": []}
// 200 {"status": "duplicate" | "ignored"}
// 401 {"message": "Invalid signature."}
// 503 {"message": "Event receiving is not configured."}
// 500 {"message": "Event processing failed."}   (the provider retries)`,
    providerInbound: `POST ${baseUrl.value}/inbound/generic
X-MailDesk-Inbound-Secret: <MAILDESK_INBOUND_SECRET>
Content-Type: application/json

{
  "from": "Jane <jane@example.com>",
  "to": ["support@acme.com"],
  "subject": "Question",
  "text": "Hi…",
  "html": "<p>Hi…</p>",
  "message_id": "<id@example.com>",
  "in_reply_to": "<…>",
  "references": "<a> <b>",
  "headers": { "X-Header": "value" },
  "attachments": [
    { "filename": "a.pdf", "content_type": "application/pdf", "content": "<base64>" }
  ]
}`,
}));

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

// Render every section in turn (within one task, so nothing flickers),
// convert it to Markdown and download the whole reference as one .md file.
const exportDocs = async () => {
    if (exporting.value) return;
    exporting.value = true;
    const previous = active.value;
    try {
        const groups = [];
        for (const group of navGroups) {
            const sections = [];
            for (const item of group.items) {
                active.value = item.id;
                await nextTick();
                const m = sectionMeta[item.id] ?? {};
                sections.push({
                    title: m.title || item.label,
                    method: m.method,
                    url: m.path ? `${baseUrl.value}${m.path}` : undefined,
                    body: htmlToMarkdown(docBodyRef.value),
                });
            }
            groups.push({ label: group.label, sections });
        }
        const markdown = buildDocsMarkdown({
            title: 'MailDesk API Documentation',
            intro: [
                `- **Base URL:** \`${baseUrl.value}\``,
                '- **Auth:** `Authorization: Bearer md_…` keys',
                `- **Limits:** ${props.rateLimit} requests / minute per key · JSON`,
            ].join('\n'),
            groups,
        });
        downloadText(markdown, DOCS_EXPORT_FILENAME);
        toast.success('Docs exported as Markdown.');
    } catch {
        toast.error('Could not export the docs.');
    } finally {
        active.value = previous;
        exporting.value = false;
    }
};

const methodClass = (method) => {
    if (method === 'GET') return 'bg-emerald-500/15 text-emerald-300';
    if (method === 'POST') return 'bg-cyan-500/15 text-cyan-300';
    return 'bg-zinc-800 text-zinc-300';
};

const blocksFor = computed(() => {
    const c = code.value;
    return (
        {
            authentication: [{ label: 'Headers', body: c.auth }],
            errors: [
                { label: 'Error bodies', body: c.errors },
                { label: 'Rate-limited response', body: c.rateHeaders },
            ],
            pagination: [{ label: 'Paginated response', body: c.pagination }],
            send: [
                { label: 'Request', body: c.sendRequest },
                { label: 'Response 201', body: c.sendResponse },
                { label: 'Response 422 (send attempted)', body: c.sendFailed },
            ],
            list: [
                { label: 'Request', body: c.list },
                { label: 'Item in "data"', body: c.listItem },
            ],
            retrieve: [{ label: 'Response 200', body: c.retrieve }],
            threads: [{ label: 'Example', body: c.threads }],
            thread: [{ label: 'Example', body: c.thread }],
            'domains-list': [{ label: 'Example', body: c.domainsList }],
            'domains-create': [{ label: 'Example', body: c.domainsCreate }],
            webhooks: [
                { label: 'Request headers', body: c.webhookHeaders },
                { label: 'Body', body: c.webhookPayload },
            ],
            verify: [
                { label: 'Node.js', body: c.verifyNode },
                { label: 'PHP', body: c.verifyPhp },
            ],
            'provider-events': [{ label: 'Example', body: c.providerEvents }],
            'provider-inbound': [{ label: 'Generic driver example', body: c.providerInbound }],
            quickstart: [{ label: 'cURL', body: c.sendRequest }],
        }[active.value] ?? []
    );
});
</script>

<template>
    <Head title="API Docs" />

    <AppLayout>
        <PageHeader
            title="API Documentation"
            description="HTTP API reference for sending mail, reading your inbox, domains and webhooks."
        >
            <template #actions>
                <button
                    type="button"
                    class="md-btn-ghost"
                    title="Download the full API docs as Markdown (.md)"
                    :disabled="exporting"
                    @click="exportDocs"
                >
                    <Download :size="14" />
                    Export
                </button>
                <Link :href="route('api-keys')" class="md-btn-ghost">
                    <KeyRound :size="14" />
                    API keys
                </Link>
            </template>
        </PageHeader>

        <div class="mb-6 grid gap-3 sm:grid-cols-3">
            <div class="md-card flex items-start gap-3 p-4">
                <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-cyan-400/10 text-cyan-300">
                    <Zap :size="16" />
                </div>
                <div>
                    <p class="text-sm font-medium text-white">Base URL</p>
                    <code class="mt-1 block break-all text-xs text-cyan-300">{{ baseUrl }}</code>
                </div>
            </div>
            <div class="md-card flex items-start gap-3 p-4">
                <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-emerald-400/10 text-emerald-300">
                    <Shield :size="16" />
                </div>
                <div>
                    <p class="text-sm font-medium text-white">Auth</p>
                    <p class="mt-1 text-xs text-zinc-400">
                        Bearer token · <code class="text-zinc-300">md_…</code> keys
                    </p>
                </div>
            </div>
            <div class="md-card flex items-start gap-3 p-4">
                <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-amber-400/10 text-amber-300">
                    <BookOpen :size="16" />
                </div>
                <div>
                    <p class="text-sm font-medium text-white">Limits</p>
                    <p class="mt-1 text-xs text-zinc-400">
                        {{ rateLimit }} requests / minute per key · JSON
                    </p>
                </div>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-[240px_minmax(0,1fr)]">
            <aside class="lg:sticky lg:top-20 lg:self-start">
                <div class="relative mb-3">
                    <Search :size="14" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-zinc-500" />
                    <input v-model="navQuery" type="search" placeholder="Search docs…" class="md-input pl-9 text-sm" />
                </div>
                <nav class="max-h-[70vh] space-y-4 overflow-y-auto pr-1">
                    <div v-for="group in filteredNav" :key="group.label">
                        <p class="mb-1.5 px-3 text-[10px] font-semibold uppercase tracking-wider text-zinc-600">
                            {{ group.label }}
                        </p>
                        <button
                            v-for="item in group.items"
                            :key="item.id"
                            type="button"
                            class="flex w-full items-center justify-between rounded-lg px-3 py-1.5 text-left text-sm transition"
                            :class="active === item.id ? 'bg-cyan-400/10 text-cyan-300' : 'text-zinc-400 hover:bg-zinc-900 hover:text-zinc-200'"
                            @click="select(item.id)"
                        >
                            <span>{{ item.label }}</span>
                            <ChevronRight v-if="active === item.id" :size="12" class="opacity-70" />
                        </button>
                    </div>
                    <p v-if="!filteredNav.length" class="px-3 text-xs text-zinc-500">No matching sections.</p>
                </nav>
            </aside>

            <article ref="contentRef" class="md-card min-w-0 p-6 sm:p-8">
                <header class="mb-6 border-b border-zinc-800 pb-5">
                    <div v-if="meta.method" class="mb-3 flex flex-wrap items-center gap-2">
                        <span class="rounded-md px-2 py-0.5 font-mono text-[11px] font-semibold" :class="methodClass(meta.method)">
                            {{ meta.method }}
                        </span>
                        <code class="text-sm text-zinc-300">{{ baseUrl }}{{ meta.path }}</code>
                    </div>
                    <h2 class="text-xl font-semibold tracking-tight text-white">{{ meta.title }}</h2>
                </header>

                <div ref="docBodyRef" class="space-y-5 text-sm leading-relaxed text-zinc-400">
                    <template v-if="active === 'introduction'">
                        <p>
                            The MailDesk API sends transactional email from your
                            verified domains, reads your shared inbox and
                            manages sending domains. Requests and responses are
                            JSON; every endpoint below lives under the base URL.
                        </p>
                        <div class="overflow-x-auto rounded-xl border border-zinc-800">
                            <table class="min-w-full text-left text-sm">
                                <thead class="border-b border-zinc-800 text-xs uppercase tracking-wide text-zinc-500">
                                    <tr>
                                        <th class="px-4 py-2.5 font-medium">Endpoint</th>
                                        <th class="px-4 py-2.5 font-medium">Key</th>
                                        <th class="px-4 py-2.5 font-medium">Purpose</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-zinc-900 text-zinc-300">
                                    <tr><td class="px-4 py-2 font-mono text-xs">POST /emails</td><td class="px-4 py-2 text-xs">Full or Sending</td><td class="px-4 py-2 text-zinc-400">Send an email</td></tr>
                                    <tr><td class="px-4 py-2 font-mono text-xs">GET /emails</td><td class="px-4 py-2 text-xs">Full</td><td class="px-4 py-2 text-zinc-400">List messages (paginated)</td></tr>
                                    <tr><td class="px-4 py-2 font-mono text-xs">GET /emails/{uuid}</td><td class="px-4 py-2 text-xs">Full</td><td class="px-4 py-2 text-zinc-400">Retrieve one message</td></tr>
                                    <tr><td class="px-4 py-2 font-mono text-xs">GET /inbox/threads</td><td class="px-4 py-2 text-xs">Full</td><td class="px-4 py-2 text-zinc-400">List inbox threads (paginated)</td></tr>
                                    <tr><td class="px-4 py-2 font-mono text-xs">GET /inbox/threads/{thread}</td><td class="px-4 py-2 text-xs">Full</td><td class="px-4 py-2 text-zinc-400">Retrieve a thread with its messages</td></tr>
                                    <tr><td class="px-4 py-2 font-mono text-xs">GET /domains</td><td class="px-4 py-2 text-xs">Full</td><td class="px-4 py-2 text-zinc-400">List domains</td></tr>
                                    <tr><td class="px-4 py-2 font-mono text-xs">POST /domains</td><td class="px-4 py-2 text-xs">Full</td><td class="px-4 py-2 text-zinc-400">Add a sending domain</td></tr>
                                    <tr><td class="px-4 py-2 font-mono text-xs">GET /contacts</td><td class="px-4 py-2 text-xs">Full</td><td class="px-4 py-2 text-zinc-400">List contacts</td></tr>
                                    <tr><td class="px-4 py-2 font-mono text-xs">POST /contacts</td><td class="px-4 py-2 text-xs">Full</td><td class="px-4 py-2 text-zinc-400">Create or update a contact</td></tr>
                                    <tr><td class="px-4 py-2 font-mono text-xs">GET /segments</td><td class="px-4 py-2 text-xs">Full</td><td class="px-4 py-2 text-zinc-400">List segments</td></tr>
                                    <tr><td class="px-4 py-2 font-mono text-xs">GET /suppressions</td><td class="px-4 py-2 text-xs">Full</td><td class="px-4 py-2 text-zinc-400">List suppressions</td></tr>
                                    <tr><td class="px-4 py-2 font-mono text-xs">GET /templates</td><td class="px-4 py-2 text-xs">Full</td><td class="px-4 py-2 text-zinc-400">List templates</td></tr>
                                    <tr><td class="px-4 py-2 font-mono text-xs">POST /events</td><td class="px-4 py-2 text-xs">Full</td><td class="px-4 py-2 text-zinc-400">Fire an automation event</td></tr>
                                    <tr><td class="px-4 py-2 font-mono text-xs">POST /events/{driver}</td><td class="px-4 py-2 text-xs">None (signed)</td><td class="px-4 py-2 text-zinc-400">Provider delivery events</td></tr>
                                    <tr><td class="px-4 py-2 font-mono text-xs">POST /inbound/{driver}</td><td class="px-4 py-2 text-xs">None (signed)</td><td class="px-4 py-2 text-zinc-400">Provider inbound mail</td></tr>
                                    <tr><td class="px-4 py-2 font-mono text-xs">POST /payments/monipay/webhook</td><td class="px-4 py-2 text-xs">None (signed)</td><td class="px-4 py-2 text-zinc-400">Internal billing callback, not for API clients</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <p>
                            Webhooks and API keys are managed in the dashboard.
                            Contacts, segments, suppressions, templates, and
                            automation events are available under
                            <code class="text-zinc-300">/api/v1</code>. Start with
                            <button type="button" class="text-cyan-300 hover:underline" @click="select('quickstart')">Quickstart</button>
                            or create a key under
                            <Link :href="route('api-keys')" class="text-cyan-300 hover:underline">API keys</Link>.
                        </p>
                    </template>

                    <template v-else-if="active === 'authentication'">
                        <p>
                            Send your key in the <code class="text-zinc-300">Authorization</code>
                            header on every request. Keys start with
                            <code class="text-zinc-300">md_</code> and are shown once when created or rotated.
                        </p>
                        <div class="overflow-x-auto rounded-xl border border-zinc-800">
                            <table class="min-w-full text-left text-sm">
                                <thead class="border-b border-zinc-800 text-xs uppercase tracking-wide text-zinc-500">
                                    <tr>
                                        <th class="px-4 py-2.5 font-medium">Permission</th>
                                        <th class="px-4 py-2.5 font-medium">Access</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-zinc-900 text-zinc-300">
                                    <tr><td class="px-4 py-3 text-xs">Full access</td><td class="px-4 py-3 text-zinc-400">Every endpoint.</td></tr>
                                    <tr><td class="px-4 py-3 text-xs">Sending access</td><td class="px-4 py-3 text-zinc-400">Only <code>POST /emails</code>. Every other endpoint returns <code>403</code>.</td></tr>
                                    <tr><td class="px-4 py-3 text-xs">Domain scope</td><td class="px-4 py-3 text-zinc-400">A key limited to one domain can only send when the <code>from</code> address is on exactly that domain (subdomains don't count); otherwise <code>403</code>.</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <ul class="list-disc space-y-2 pl-5">
                            <li>Missing or malformed keys and unknown keys return <code class="text-zinc-300">401</code>.</li>
                            <li>Keys can have an expiry date. Expired keys return <code class="text-zinc-300">401 {"message": "API key expired."}</code>.</li>
                            <li><strong class="text-zinc-300">Revoke</strong> a key on the API keys page to disable it for good; it stays listed as Revoked and returns <code class="text-zinc-300">401 {"message": "API key revoked."}</code>.</li>
                            <li><strong class="text-zinc-300">Rotate</strong> a key on the API keys page: you get a new secret with the same name, permission, domain scope and validity period, and the old key is revoked immediately. Revoked keys can't be rotated.</li>
                            <li>Never embed keys in browser or mobile apps.</li>
                        </ul>
                    </template>

                    <template v-else-if="active === 'errors'">
                        <p>
                            Errors are JSON with a <code class="text-zinc-300">message</code>.
                            Validation errors (<code class="text-zinc-300">422</code>) also include
                            <code class="text-zinc-300">errors</code>, keyed by field.
                        </p>
                        <div class="overflow-x-auto rounded-xl border border-zinc-800">
                            <table class="min-w-full text-left text-sm">
                                <thead class="border-b border-zinc-800 text-xs uppercase tracking-wide text-zinc-500">
                                    <tr>
                                        <th class="px-4 py-2.5 font-medium">Status</th>
                                        <th class="px-4 py-2.5 font-medium">When</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-zinc-900">
                                    <tr v-for="s in statusCodes" :key="s.code">
                                        <td class="whitespace-nowrap px-4 py-3 font-mono text-xs text-cyan-300">{{ s.code }}</td>
                                        <td class="px-4 py-3 text-zinc-400">{{ s.desc }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="rounded-xl border border-zinc-800 bg-zinc-950/60 p-4">
                            <p class="text-xs font-medium text-zinc-300">Rate limit</p>
                            <ul class="mt-2 space-y-1 text-zinc-400">
                                <li>· {{ rateLimit }} requests per minute, counted per API key.</li>
                                <li>· Every authenticated response carries <code>X-RateLimit-Limit</code> and <code>X-RateLimit-Remaining</code>.</li>
                                <li>· Over the limit you get <code>429</code> with <code>Retry-After</code> (seconds) and <code>X-RateLimit-Reset</code> (unix time).</li>
                            </ul>
                        </div>
                    </template>

                    <template v-else-if="active === 'pagination'">
                        <p>
                            <code class="text-zinc-300">GET /emails</code> and
                            <code class="text-zinc-300">GET /inbox/threads</code> are page-based.
                            Pass <code class="text-zinc-300">page</code> (default 1) and
                            <code class="text-zinc-300">per_page</code> (default 25, maximum 100)
                            and follow <code class="text-zinc-300">next_page_url</code> until it is
                            <code class="text-zinc-300">null</code>. Results are newest first.
                            <code class="text-zinc-300">GET /domains</code> returns a plain array.
                        </p>
                    </template>

                    <template v-else-if="active === 'send'">
                        <p>
                            Sends one email immediately through the workspace's
                            mail provider. The sender domain must be
                            <strong class="text-zinc-300">verified</strong> in this workspace
                            (otherwise <code>422</code> on <code>from</code>). Recipients on the
                            suppression list are not mailed: the message is stored with status
                            <code>suppressed</code> and the call returns <code>422</code>.
                            Attachments and scheduled sends are not available over the API.
                        </p>
                        <div class="overflow-x-auto rounded-xl border border-zinc-800">
                            <table class="min-w-full text-left text-sm">
                                <thead class="border-b border-zinc-800 text-xs uppercase tracking-wide text-zinc-500">
                                    <tr>
                                        <th class="px-4 py-2.5 font-medium">Field</th>
                                        <th class="px-4 py-2.5 font-medium">Type</th>
                                        <th class="px-4 py-2.5 font-medium">Description</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-zinc-900">
                                    <tr v-for="p in sendParams" :key="p.name">
                                        <td class="whitespace-nowrap px-4 py-3 font-mono text-xs text-cyan-300">
                                            {{ p.name }}
                                            <span v-if="p.required" class="ml-1 text-[10px] text-rose-400">required</span>
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-3 font-mono text-[11px] text-zinc-500">{{ p.type }}</td>
                                        <td class="px-4 py-3 text-zinc-400">{{ p.desc }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <p>
                            The response <code class="text-zinc-300">id</code> is the message UUID used by
                            <button type="button" class="text-cyan-300 hover:underline" @click="select('retrieve')">Retrieve email</button>
                            and in webhook payloads.
                        </p>
                    </template>

                    <template v-else-if="active === 'list'">
                        <p>
                            Lists the workspace's messages, outbound and inbound,
                            newest first, as a
                            <button type="button" class="text-cyan-300 hover:underline" @click="select('pagination')">paginated</button>
                            response. Internal fields (<code>meta</code>, <code>bcc</code>,
                            <code>headers</code>) are never returned. There are no filter parameters.
                        </p>
                    </template>

                    <template v-else-if="active === 'retrieve'">
                        <p>
                            Fetch one message by its UUID (the <code>id</code> returned by
                            <code>POST /emails</code>). Unknown ids return <code>404</code>.
                        </p>
                    </template>

                    <template v-else-if="active === 'threads'">
                        <p>
                            Lists inbox conversations, most recent activity first,
                            as a paginated response. Each thread includes its latest
                            message in <code>messages</code>. There are no filter parameters.
                        </p>
                    </template>

                    <template v-else-if="active === 'thread'">
                        <p>
                            Fetch a thread by its numeric id with all of its messages,
                            oldest first. Replying is only available in the dashboard.
                        </p>
                    </template>

                    <template v-else-if="active === 'domains-list'">
                        <p>Returns every domain in the workspace (not paginated), newest first.</p>
                    </template>

                    <template v-else-if="active === 'domains-create'">
                        <p>
                            Adds a domain with status <code>pending</code> and the DNS
                            records to publish. The only field is <code>name</code>
                            (required, up to 255 characters, stored lower-case).
                            Verification runs from the dashboard's Domains page and on
                            MailDesk's schedule; there is no verify endpoint.
                        </p>
                    </template>

                    <template v-else-if="active === 'webhooks'">
                        <p>
                            Add webhook endpoints on the Webhooks page. MailDesk POSTs
                            a signed JSON body to every enabled endpoint subscribed to
                            the event. Endpoints must use <code>https://</code> and
                            may not point to private, loopback, link-local or other
                            internal addresses (checked when saved and again before every delivery).
                        </p>
                        <div class="overflow-x-auto rounded-xl border border-zinc-800">
                            <table class="min-w-full text-left text-sm">
                                <thead class="border-b border-zinc-800 text-xs uppercase tracking-wide text-zinc-500">
                                    <tr>
                                        <th class="px-4 py-2.5 font-medium">Event</th>
                                        <th class="px-4 py-2.5 font-medium">data fields</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-zinc-900 text-zinc-400">
                                    <tr><td class="px-4 py-2 font-mono text-xs text-cyan-300">email.sent</td><td class="px-4 py-2 text-xs">id, subject, to, from, status. Sent when the provider accepts the message.</td></tr>
                                    <tr><td class="px-4 py-2 font-mono text-xs text-cyan-300">email.delivered · email.bounced · email.complained · email.opened · email.clicked</td><td class="px-4 py-2 text-xs">id, status, to, subject, occurred_at, bounce_type, reason. From provider events; opens and clicks need tracking enabled at your provider.</td></tr>
                                    <tr><td class="px-4 py-2 font-mono text-xs text-cyan-300">email.received</td><td class="px-4 py-2 text-xs">id, thread_id, mailbox, from, to, subject, status.</td></tr>
                                    <tr><td class="px-4 py-2 font-mono text-xs text-cyan-300">webhook.test</td><td class="px-4 py-2 text-xs">webhook_id, message. Sent by the "Send test event" button only.</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <p class="text-xs text-zinc-500">
                            Subscribable events: <span class="font-mono">{{ webhookEvents.join(', ') }}</span>.
                        </p>
                    </template>

                    <template v-else-if="active === 'verify'">
                        <p>
                            Each request is signed with the endpoint's signing secret
                            (the full <code>whsec_…</code> string is the HMAC key).
                            Signatures are lowercase hex HMAC-SHA256 values computed
                            over the <strong class="text-zinc-300">raw request body</strong>;
                            don't re-encode the JSON before checking.
                        </p>
                        <ul class="list-disc space-y-2 pl-5">
                            <li><code class="text-zinc-300">X-MailDesk-Signature-V2</code> (recommended) = HMAC of <code class="text-zinc-300">{X-MailDesk-Timestamp}.{raw body}</code>. Reject timestamps more than 5 minutes old to block replays.</li>
                            <li><code class="text-zinc-300">X-MailDesk-Signature</code> (legacy, unchanged) = HMAC of the raw body alone.</li>
                            <li>Compare in constant time. Rotating the secret on the webhook page invalidates the old one immediately.</li>
                        </ul>
                    </template>

                    <template v-else-if="active === 'retries'">
                        <ul class="list-disc space-y-2 pl-5">
                            <li>Any <code>2xx</code> response within {{ webhookRetry.timeout }} seconds counts as delivered.</li>
                            <li>Network errors, timeouts, <code>5xx</code>, <code>408</code> and <code>429</code> are retried: up to {{ webhookRetry.attempts }} attempts in total, waiting {{ retryDelays }} between them.</li>
                            <li>Other <code>4xx</code> responses and redirects (<code>3xx</code>, never followed) are not retried.</li>
                            <li>Retries reuse the same body and <code>X-MailDesk-Delivery</code> id; use it to ignore duplicates. Each attempt has a fresh timestamp and V2 signature.</li>
                            <li>Every attempt, its status and the error are shown in the endpoint's delivery history.</li>
                        </ul>
                    </template>

                    <template v-else-if="active === 'provider-events'">
                        <p>
                            For configuring your mail provider, not for API clients.
                            Point Resend's webhook at this URL with
                            <code>driver</code> = <code>resend</code>. Requests are verified
                            with the Svix signature (<code>RESEND_WEBHOOK_SECRET</code>), no API key.
                            Handled events: <code>email.delivered</code>, <code>email.bounced</code>,
                            <code>email.complained</code>, <code>email.opened</code>,
                            <code>email.clicked</code>; anything else is acknowledged and ignored.
                            Hard bounces and complaints add the recipient to the suppression list.
                            Limited to 600 requests per minute.
                        </p>
                    </template>

                    <template v-else-if="active === 'provider-inbound'">
                        <p>
                            For configuring inbound mail, not for API clients.
                            <code>driver</code> is <code>resend</code> (Svix-signed with
                            <code>RESEND_WEBHOOK_SECRET</code>; it also accepts the delivery
                            events above, so one Resend webhook can cover both) or
                            <code>generic</code> (requires the
                            <code>X-MailDesk-Inbound-Secret</code> header). Responses:
                            <code>2xx</code> done, <code>401</code> bad signature,
                            <code>503</code> retry later. Limited to 600 requests per minute.
                        </p>
                    </template>

                    <template v-else-if="active === 'quickstart'">
                        <ol class="list-decimal space-y-2 pl-5">
                            <li>Verify a sending domain on the Domains page.</li>
                            <li>Create an API key (Sending access is enough to send).</li>
                            <li>Replace the key and the <code>from</code> address below and run it.</li>
                        </ol>
                    </template>

                    <div v-for="(block, i) in blocksFor" :key="active + i">
                        <div class="mb-2 flex items-center justify-between gap-2">
                            <p data-md-label class="text-xs font-medium uppercase tracking-wide text-zinc-500">{{ block.label }}</p>
                            <button type="button" data-md-skip class="md-btn-ghost" @click="copyText(block.body, active + i)">
                                <Check v-if="copied === active + i" :size="14" class="text-emerald-400" />
                                <Copy v-else :size="14" />
                                Copy
                            </button>
                        </div>
                        <pre class="overflow-x-auto rounded-lg border border-zinc-800 bg-black/50 p-4 text-[12px] leading-relaxed text-zinc-300">{{ block.body }}</pre>
                    </div>
                </div>
            </article>
        </div>
    </AppLayout>
</template>
