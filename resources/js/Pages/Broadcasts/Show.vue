<script setup>
import { computed, onBeforeUnmount, onMounted } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import EmailFrame from '@/Components/EmailFrame.vue';
import { useDesigns } from '@/composables/useDesigns';
import { useToast } from '@/composables/useToast';
import {
    ArrowLeft,
    Copy,
    Megaphone,
    Pencil,
    Send,
    Users,
} from '@lucide/vue';

const props = defineProps({
    id: { type: [String, Number], required: true },
    broadcast: { type: Object, required: true },
    counts: { type: Object, default: () => ({}) },
    recipients: { type: Array, default: () => [] },
});

const toast = useToast();
const { apply, find } = useDesigns();

const broadcast = computed(() => props.broadcast);
const previewHtml = computed(() => apply(broadcast.value.html || '', broadcast.value.design_key));
const designName = computed(() => find(broadcast.value.design_key)?.name || 'Plain mail');

const num = (v) => Number(v || 0).toLocaleString();
const pct = (part, whole) =>
    whole > 0 ? `${Math.round((part / whole) * 1000) / 10}%` : '—';

const inFlight = computed(() =>
    ['queued', 'sending'].includes(broadcast.value.status),
);

const metrics = computed(() => {
    const c = props.counts || {};
    const sent = c.sent || 0;
    return [
        { label: 'Recipients', value: num(c.recipients), hint: c.skipped ? `${num(c.skipped)} skipped` : null },
        { label: 'Sent', value: num(sent), hint: c.pending ? `${num(c.pending)} in queue` : (c.failed ? `${num(c.failed)} failed` : null) },
        { label: 'Delivered', value: num(c.delivered), hint: sent ? pct(c.delivered, sent) : null },
        { label: 'Opened', value: num(c.opened), hint: sent ? pct(c.opened, sent) : null },
        { label: 'Bounced', value: num(c.bounced), hint: c.complained ? `${num(c.complained)} complaints` : null },
        { label: 'Unsubscribed', value: num(c.unsubscribed), hint: null },
    ];
});

// While the queue works through a broadcast, refresh the counts.
let timer = null;
onMounted(() => {
    timer = setInterval(() => {
        if (inFlight.value) {
            router.reload({ only: ['broadcast', 'counts', 'recipients'] });
        }
    }, 3000);
});
onBeforeUnmount(() => clearInterval(timer));

const sendNow = () => {
    if (!confirm(`Send "${props.broadcast.name}" to ${props.broadcast.audience}?`)) return;
    router.post(route('broadcasts.send', props.broadcast.id), {}, { preserveScroll: true });
};

const goEdit = () => router.visit(route('broadcasts.create'));

const duplicate = () => {
    router.post(
        route('broadcasts.store'),
        { source_id: props.broadcast.id, name: props.broadcast.name },
        {
            onSuccess: () => toast.success('Broadcast duplicated.'),
        },
    );
};
</script>

<template>
    <Head :title="broadcast.name" />

    <AppLayout>
        <div class="mb-6">
            <Link
                :href="route('broadcasts')"
                class="inline-flex items-center gap-1.5 text-sm text-zinc-500 hover:text-cyan-300"
            >
                <ArrowLeft :size="14" />
                Broadcasts
            </Link>
        </div>

        <div
            class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
        >
            <div class="flex items-start gap-4">
                <div
                    class="flex h-12 w-12 items-center justify-center rounded-xl border border-cyan-400/30 bg-cyan-400/10 text-cyan-300"
                >
                    <Megaphone :size="22" />
                </div>
                <div>
                    <div
                        class="text-xs uppercase tracking-wide text-zinc-500"
                    >
                        Broadcast
                    </div>
                    <h1 class="mt-1 text-2xl font-semibold text-white">
                        {{ broadcast.name }}
                    </h1>
                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        <StatusBadge :status="broadcast.status" />
                        <span class="text-sm text-zinc-500">
                            {{ broadcast.sent }}
                        </span>
                    </div>
                </div>
            </div>
            <div class="flex flex-wrap gap-2">
                <button
                    v-if="broadcast.status === 'draft'"
                    type="button"
                    class="md-btn-primary"
                    data-testid="broadcast-send"
                    @click="sendNow"
                >
                    <Send :size="16" />
                    Send now
                </button>
                <button type="button" class="md-btn-ghost" @click="duplicate">
                    <Copy :size="16" />
                    Duplicate
                </button>
                <button type="button" class="md-btn-solid" @click="goEdit">
                    <Pencil :size="16" />
                    Edit
                </button>
            </div>
        </div>

        <div class="mb-6 grid gap-3 sm:grid-cols-3 xl:grid-cols-6">
            <div
                v-for="m in metrics"
                :key="m.label"
                class="md-card p-4"
            >
                <div class="text-xs uppercase tracking-wide text-zinc-500">
                    {{ m.label }}
                </div>
                <div class="mt-1 text-2xl font-semibold tabular-nums text-white">
                    {{ m.value }}
                </div>
                <div class="mt-0.5 h-4 text-xs text-zinc-500">{{ m.hint }}</div>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-[1fr_320px]">
            <section class="md-card space-y-4 p-5">
                <h2 class="text-sm font-medium text-white">Preview</h2>
                <div class="overflow-hidden rounded-xl border border-zinc-800">
                    <div class="border-b border-zinc-800 px-4 py-3 text-xs text-zinc-500">
                        <div>
                            <span class="mr-1 text-zinc-600">From:</span>
                            <span class="text-zinc-300">{{ broadcast.from || 'Default sender' }}</span>
                        </div>
                        <div class="mt-1">
                            <span class="mr-1 text-zinc-600">Subject:</span>
                            <span class="text-zinc-300">{{ broadcast.subject }}</span>
                        </div>
                    </div>
                    <EmailFrame :html="previewHtml" :min-height="240" title="Broadcast preview" />
                </div>
                <p class="text-xs text-zinc-500">
                    Each recipient gets a personal unsubscribe link in the footer
                    (or wherever you put <code class="text-zinc-400">{{ '{' + '{unsubscribe_url}' + '}' }}</code>).
                </p>
            </section>

            <aside class="space-y-4">
                <section class="md-card space-y-3 p-5">
                    <h2 class="text-sm font-medium text-white">Details</h2>
                    <dl class="space-y-3 text-sm">
                        <div class="flex justify-between gap-3">
                            <dt class="text-zinc-500">Design</dt>
                            <dd class="text-right text-zinc-200">{{ designName }}</dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-zinc-500">Audience</dt>
                            <dd
                                class="flex items-center gap-1.5 text-right text-zinc-200"
                            >
                                <Users :size="13" class="text-zinc-500" />
                                {{ broadcast.audience }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-zinc-500">Recipients</dt>
                            <dd class="tabular-nums text-zinc-200">
                                {{ num(counts.recipients ?? broadcast.recipients) }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-zinc-500">Status</dt>
                            <dd>
                                <StatusBadge :status="broadcast.status" />
                            </dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-zinc-500">Sent</dt>
                            <dd class="text-zinc-200">{{ broadcast.sent }}</dd>
                        </div>
                    </dl>
                </section>

                <section class="md-card p-5" data-testid="broadcast-recipients">
                    <h2 class="mb-3 text-sm font-medium text-white">
                        Recipients
                        <span v-if="counts.recipients > recipients.length" class="font-normal text-zinc-500">
                            (latest {{ recipients.length }})
                        </span>
                    </h2>
                    <p v-if="!recipients.length" class="text-sm text-zinc-500">
                        {{ broadcast.status === 'draft' ? 'Recipients are picked when you send.' : 'Preparing the recipient list…' }}
                    </p>
                    <ul v-else class="max-h-80 space-y-2 overflow-y-auto pr-1 text-sm">
                        <li
                            v-for="r in recipients"
                            :key="r.id"
                            class="flex items-center justify-between gap-3"
                            :title="r.error || ''"
                        >
                            <span class="truncate text-zinc-300">{{ r.email }}</span>
                            <StatusBadge :status="r.status" />
                        </li>
                    </ul>
                </section>
            </aside>
        </div>
    </AppLayout>
</template>
