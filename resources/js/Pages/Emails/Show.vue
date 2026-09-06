<script setup>
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import {
    ArrowLeft,
    CheckCircle2,
    Code2,
    Copy,
    FileText,
    Mail,
    MoreHorizontal,
    Paperclip,
    Send,
} from '@lucide/vue';

const props = defineProps({
    email: { type: Object, required: true },
});

const email = computed(() => props.email);

const tab = ref('preview');
const copied = ref(false);

const copyId = async () => {
    try {
        await navigator.clipboard.writeText(email.value.id);
        copied.value = true;
        setTimeout(() => (copied.value = false), 1500);
    } catch {
        /* ignore */
    }
};
</script>

<template>
    <Head :title="email.subject" />

    <AppLayout>
        <div class="mb-6">
            <Link
                :href="route('emails')"
                class="inline-flex items-center gap-1.5 text-sm text-zinc-500 hover:text-cyan-300"
            >
                <ArrowLeft :size="14" />
                Emails
            </Link>
        </div>

        <div class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="flex items-start gap-4">
                <div
                    class="flex h-12 w-12 items-center justify-center rounded-xl border border-emerald-500/30 bg-emerald-500/10 text-emerald-400"
                >
                    <Mail :size="22" />
                </div>
                <div>
                    <div class="text-xs uppercase tracking-wide text-zinc-500">
                        Email
                    </div>
                    <h1 class="mt-1 text-2xl font-semibold text-white">
                        {{ email.to }}
                    </h1>
                    <div class="mt-2">
                        <StatusBadge :status="email.status" />
                    </div>
                </div>
            </div>
            <div class="flex gap-2">
                <Link :href="route('docs')" class="md-btn-ghost">
                    <Code2 :size="16" />
                </Link>
                <button type="button" class="md-btn-ghost">
                    <MoreHorizontal :size="16" />
                </button>
            </div>
        </div>

        <div class="md-card mb-6 grid gap-6 p-5 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <div class="text-[11px] uppercase tracking-wide text-zinc-500">
                    From
                </div>
                <div class="mt-1 text-sm text-white">
                    {{ email.from_name || email.from }}
                </div>
                <div class="text-xs text-zinc-500">{{ email.from }}</div>
            </div>
            <div>
                <div class="text-[11px] uppercase tracking-wide text-zinc-500">
                    Subject
                </div>
                <div class="mt-1 text-sm text-white">{{ email.subject }}</div>
            </div>
            <div>
                <div class="text-[11px] uppercase tracking-wide text-zinc-500">
                    To
                </div>
                <div class="mt-1 text-sm text-white">{{ email.to }}</div>
            </div>
            <div>
                <div class="text-[11px] uppercase tracking-wide text-zinc-500">
                    ID
                </div>
                <button
                    type="button"
                    class="mt-1 inline-flex items-center gap-1.5 font-mono text-xs text-zinc-300 hover:text-cyan-300"
                    @click="copyId"
                >
                    msg_{{ email.id }}
                    <Copy :size="12" />
                    <span v-if="copied" class="text-cyan-300">Copied</span>
                </button>
            </div>
        </div>

        <div class="mb-6 grid gap-4 sm:grid-cols-2">
            <div class="md-card p-4">
                <div class="text-[11px] uppercase tracking-wide text-zinc-500">
                    Log
                </div>
                <div class="mt-1 font-mono text-sm text-zinc-300">
                    {{ email.log }}
                </div>
            </div>
            <div class="md-card p-4">
                <div class="text-[11px] uppercase tracking-wide text-zinc-500">
                    Attachments
                </div>
                <div
                    v-if="email.attachments?.length"
                    class="mt-2 flex flex-wrap gap-2"
                >
                    <span
                        v-for="file in email.attachments"
                        :key="file"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-800 px-2 py-1 text-xs text-zinc-300"
                    >
                        <Paperclip :size="12" />
                        {{ file }}
                    </span>
                </div>
                <div v-else class="mt-1 text-sm text-zinc-500">None</div>
            </div>
        </div>

        <!-- Timeline -->
        <div class="md-card mb-6 p-6">
            <div class="text-[11px] uppercase tracking-wide text-zinc-500">
                Events
            </div>
            <div class="relative mt-8 flex items-start justify-between px-4">
                <div
                    class="absolute left-8 right-8 top-4 h-px bg-zinc-800"
                />
                <div class="relative z-10 flex flex-col items-center gap-2">
                    <span
                        class="flex h-8 w-8 items-center justify-center rounded-full border border-zinc-700 bg-zinc-900 text-zinc-300"
                    >
                        <Send :size="14" />
                    </span>
                    <div class="text-sm font-medium text-white">Sent</div>
                    <div class="text-xs text-zinc-500">{{ email.sent_at }}</div>
                </div>
                <div class="relative z-10 flex flex-col items-center gap-2">
                    <span
                        class="flex h-8 w-8 items-center justify-center rounded-full border border-emerald-500/40 bg-emerald-500/15 text-emerald-400"
                    >
                        <CheckCircle2 :size="14" />
                    </span>
                    <div class="text-sm font-medium text-white">
                        {{
                            email.status === 'bounced'
                                ? 'Bounced'
                                : email.status === 'received'
                                  ? 'Received'
                                  : 'Delivered'
                        }}
                    </div>
                    <div class="text-xs text-zinc-500">
                        {{ email.delivered_at || email.sent_at }}
                    </div>
                </div>
            </div>
        </div>

        <!-- Content tabs -->
        <div class="md-card overflow-hidden">
            <div class="flex flex-wrap gap-1 border-b border-zinc-800 px-3 py-2">
                <button
                    v-for="t in ['preview', 'plain', 'html', 'raw', 'insights']"
                    :key="t"
                    type="button"
                    class="rounded-full px-3 py-1.5 text-xs capitalize transition"
                    :class="
                        tab === t
                            ? 'bg-zinc-800 text-white'
                            : 'text-zinc-500 hover:text-zinc-300'
                    "
                    @click="tab = t"
                >
                    {{ t === 'plain' ? 'Plain Text' : t }}
                </button>
            </div>

            <div v-if="tab === 'preview'" class="bg-zinc-900/40 p-4 sm:p-6">
                <div
                    class="mx-auto max-w-xl overflow-hidden rounded-xl bg-white text-zinc-900 shadow-lg"
                    v-html="email.html"
                />
            </div>
            <pre
                v-else-if="tab === 'plain'"
                class="overflow-x-auto p-5 text-sm text-zinc-300"
            >{{ email.text }}</pre>
            <pre
                v-else-if="tab === 'html'"
                class="overflow-x-auto p-5 text-xs text-zinc-400"
            >{{ email.html }}</pre>
            <pre
                v-else-if="tab === 'raw'"
                class="overflow-x-auto p-5 text-xs text-zinc-400"
            >From: {{ email.from }}
To: {{ email.to }}
Subject: {{ email.subject }}
Date: {{ email.sent_at }}

{{ email.text }}</pre>
            <div v-else class="p-6 text-sm text-zinc-400">
                <div class="flex items-start gap-3">
                    <FileText :size="18" class="mt-0.5 text-cyan-300" />
                    <div>
                        <div class="font-medium text-white">Insights (mock)</div>
                        <p class="mt-1">
                            Opens, clicks, and device breakdown will appear here
                            once tracking is connected.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
