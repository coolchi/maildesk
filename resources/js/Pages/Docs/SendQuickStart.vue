<script setup>
import { computed, ref } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import BrandLogo from '@/Components/BrandLogo.vue';
import { useTheme } from '@/composables/useTheme';
import { useToast } from '@/composables/useToast';
import { Check, Copy, Moon, Sun } from '@lucide/vue';

const props = defineProps({
    apiBaseUrl: { type: String, default: '/api/v1' },
    rateLimit: { type: Number, default: 120 },
});

const page = usePage();
const toast = useToast();
const { theme, setTheme } = useTheme();
const user = computed(() => page.props.auth?.user ?? null);
const copied = ref('');

const fields = [
    { name: 'from', required: true, desc: '"hello@acme.com", "Acme <hello@acme.com>", or {"email", "name"}. The domain must be verified in the workspace.' },
    { name: 'to', required: true, desc: 'A string, an array of addresses, or an array of {"email", "name"} objects.' },
    { name: 'subject', required: true, desc: 'Up to 998 characters.' },
    { name: 'html', required: false, desc: 'HTML body. Send html, text, or both.' },
    { name: 'text', required: false, desc: 'Plain-text body.' },
    { name: 'cc', required: false, desc: 'Same formats as to.' },
    { name: 'bcc', required: false, desc: 'Same formats as to. Never returned by the API.' },
    { name: 'reply_to', required: false, desc: 'Where replies should go.' },
    { name: 'tags', required: false, desc: 'Array of strings stored on the message.' },
    { name: 'headers', required: false, desc: 'Extra headers as {"Header-Name": "value"}.' },
    { name: 'signature', required: false, desc: 'Append the sender signature. Defaults to the workspace API setting.' },
];

const curl = computed(() => `curl -X POST ${props.apiBaseUrl}/emails \\
  -H "Authorization: Bearer md_your_api_key" \\
  -H "Content-Type: application/json" \\
  -H "Accept: application/json" \\
  -d '{
    "from": "Acme <hello@acme.com>",
    "to": ["customer@example.com"],
    "subject": "Your receipt",
    "html": "<p>Thanks for your order.</p>",
    "text": "Thanks for your order.",
    "reply_to": "support@acme.com"
  }'`);

const prompt = computed(() => `Integrate MailDesk email sending into this application. Use only the contract below. Do not add an SDK, and do not call any other MailDesk endpoint.

Base URL: ${props.apiBaseUrl}
Endpoint: POST ${props.apiBaseUrl}/emails
Auth header: Authorization: Bearer <API key>
The key is a server-side secret. It starts with md_. Read it from the environment variable MAILDESK_API_KEY. Never put it in frontend code, mobile apps, logs, or git.

Before the first send, the from domain must already be verified in the MailDesk workspace. A sending-only key is enough for this endpoint. If the key is limited to one domain, from must be on that exact domain (subdomains do not count).

Request JSON:
- from (required): "hello@acme.com", "Name <hello@acme.com>", or {"email":"hello@acme.com","name":"Name"}
- to (required): a string, an array of addresses, or an array of {"email","name"}
- subject (required string, max 998)
- html and/or text (send at least one)
- cc, bcc, reply_to (optional, same formats as to)
- tags (optional array of strings)
- headers (optional object of header name to value)
- signature (optional boolean)

Success is HTTP 201:
{"id":"<uuid>","status":"sent","provider":"resend","provider_message_id":"...","created_at":"..."}

HTTP 422 means either validation failed (body has "message" and "errors") or the message was stored but not delivered (body has "id" and "status" of "failed" or "suppressed").
HTTP 401: missing, invalid, expired, or revoked key.
HTTP 403: the from domain is outside this key's domain scope.
HTTP 429: more than ${props.rateLimit} requests per minute for this key. Wait the Retry-After seconds, then retry.

Implement one server-side function that sends a single email and returns the JSON body. On any non-201 response, surface the JSON message to the caller. Do not retry 4xx except 429.`);

const copyText = async (text, key) => {
    try {
        await navigator.clipboard.writeText(text);
        copied.value = key;
        toast.success('Copied.');
        window.setTimeout(() => {
            if (copied.value === key) copied.value = '';
        }, 1500);
    } catch {
        toast.error('Could not copy.');
    }
};
</script>

<template>
    <Head title="Send email — MailDesk API" />

    <div class="min-h-screen bg-black text-zinc-300">
        <header class="sticky top-0 z-20 border-b border-zinc-800/80 bg-black/80 backdrop-blur">
            <div class="mx-auto flex h-16 max-w-3xl items-center justify-between gap-4 px-5">
                <Link href="/" class="flex items-center" aria-label="MailDesk home">
                    <BrandLogo class="h-8" />
                </Link>
                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        class="rounded-full border border-zinc-800 p-2 text-zinc-400 hover:text-white"
                        :title="theme === 'dark' ? 'Light mode' : 'Dark mode'"
                        @click="setTheme(theme === 'dark' ? 'light' : 'dark')"
                    >
                        <Sun v-if="theme === 'dark'" :size="14" />
                        <Moon v-else :size="14" />
                    </button>
                    <Link
                        v-if="user"
                        :href="route('dashboard')"
                        class="md-btn-solid"
                    >
                        Open app
                    </Link>
                    <Link v-else :href="route('login')" class="md-btn-ghost">Log in</Link>
                </div>
            </div>
        </header>

        <main class="mx-auto max-w-3xl space-y-10 px-5 py-12">
            <div>
                <p class="text-xs font-medium uppercase tracking-wide text-cyan-300">Public docs</p>
                <h1 class="mt-2 text-3xl font-semibold text-white">Send an email</h1>
                <p class="mt-3 text-sm leading-relaxed text-zinc-400">
                    One request sends a message from a domain you have verified.
                    This page is the public quick start. The rest of the API reference is in the app.
                </p>
            </div>

            <section class="space-y-3">
                <h2 class="text-lg font-medium text-white">Quick start</h2>
                <ol class="list-decimal space-y-2 pl-5 text-sm text-zinc-300">
                    <li>Create a workspace and verify the domain you will send from.</li>
                    <li>Create an API key. Sending access is enough.</li>
                    <li>POST JSON to <code class="text-cyan-200">{{ apiBaseUrl }}/emails</code> with <code class="text-cyan-200">Authorization: Bearer md_…</code>.</li>
                </ol>
                <p class="text-sm text-zinc-500">
                    Keep the key on your server. A key in a browser or mobile app can be copied.
                </p>
            </section>

            <section class="space-y-3">
                <div class="flex items-center justify-between gap-3">
                    <h2 class="text-lg font-medium text-white">Request</h2>
                    <button type="button" class="md-btn-ghost" data-testid="copy-curl" @click="copyText(curl, 'curl')">
                        <Check v-if="copied === 'curl'" :size="14" class="text-emerald-400" />
                        <Copy v-else :size="14" />
                        Copy
                    </button>
                </div>
                <p class="font-mono text-xs text-zinc-500">POST {{ apiBaseUrl }}/emails</p>
                <pre class="overflow-x-auto rounded-xl border border-zinc-800 bg-zinc-950 p-4 text-[12px] leading-relaxed text-zinc-300">{{ curl }}</pre>
                <div class="overflow-x-auto rounded-xl border border-zinc-800">
                    <table class="min-w-full text-left text-sm">
                        <thead class="border-b border-zinc-800 text-xs uppercase tracking-wide text-zinc-500">
                            <tr>
                                <th class="px-4 py-2.5 font-medium">Field</th>
                                <th class="px-4 py-2.5 font-medium">Description</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-900">
                            <tr v-for="field in fields" :key="field.name">
                                <td class="whitespace-nowrap px-4 py-3 font-mono text-xs text-cyan-300">
                                    {{ field.name }}
                                    <span v-if="field.required" class="ml-1 text-[10px] text-rose-400">required</span>
                                </td>
                                <td class="px-4 py-3 text-zinc-400">{{ field.desc }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="space-y-3 text-sm leading-relaxed">
                <h2 class="text-lg font-medium text-white">Response</h2>
                <p>
                    <code class="text-zinc-200">201</code> means the provider accepted the message.
                    The <code class="text-zinc-200">id</code> is the message UUID.
                </p>
                <pre class="overflow-x-auto rounded-xl border border-zinc-800 bg-zinc-950 p-4 text-[12px] leading-relaxed text-zinc-300">{
  "id": "9d1c6f3e-6c1b-4f0e-9a51-2f0f5b8a7c11",
  "status": "sent",
  "provider": "resend",
  "provider_message_id": "4ef9a417-02e9-4d39-ad75-9611e0fcc33c",
  "created_at": "2026-09-28T10:04:12.000000Z"
}</pre>
                <ul class="list-disc space-y-2 pl-5 text-zinc-400">
                    <li><code class="text-zinc-200">422</code> — invalid fields, an unverified from domain, or the message was stored with status <code>failed</code> or <code>suppressed</code>.</li>
                    <li><code class="text-zinc-200">401</code> — missing, invalid, expired, or revoked key.</li>
                    <li><code class="text-zinc-200">403</code> — the from domain is outside this key’s domain scope.</li>
                    <li><code class="text-zinc-200">429</code> — more than {{ rateLimit }} requests in a minute. Wait <code>Retry-After</code> seconds.</li>
                </ul>
            </section>

            <section class="space-y-3" data-testid="ai-integration-prompt">
                <div class="flex items-center justify-between gap-3">
                    <h2 class="text-lg font-medium text-white">AI integration prompt</h2>
                    <button type="button" class="md-btn-primary" data-testid="copy-ai-prompt" @click="copyText(prompt, 'prompt')">
                        <Check v-if="copied === 'prompt'" :size="14" />
                        <Copy v-else :size="14" />
                        Copy prompt
                    </button>
                </div>
                <p class="text-sm text-zinc-400">
                    Paste this into your coding assistant. It has the live base URL and the send contract, so the assistant does not invent a client library.
                </p>
                <pre class="overflow-x-auto whitespace-pre-wrap rounded-xl border border-zinc-800 bg-zinc-950 p-4 text-[12px] leading-relaxed text-zinc-300">{{ prompt }}</pre>
            </section>
        </main>
    </div>
</template>
