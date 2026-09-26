<script setup>
import { ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { useComposeModal } from '@/composables/useComposeModal';
import {
    AlertCircle,
    ChevronLeft,
    ChevronRight,
    PenSquare,
    Search,
    Send,
} from '@lucide/vue';

const props = defineProps({
    emails: { type: Array, default: () => [] },
    pagination: { type: Object, default: () => ({}) },
    counts: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({ status: 'all', q: '' }) },
});

const { open: openCompose } = useComposeModal();
const search = ref(props.filters.q || '');

const tabs = [
    { key: 'all', label: 'All' },
    { key: 'delivered', label: 'Delivered' },
    { key: 'sent', label: 'In transit' },
    { key: 'failed', label: 'Failed' },
    { key: 'scheduled', label: 'Scheduled' },
];

const visit = (params) => {
    router.get(
        route('sent'),
        {
            status: props.filters.status !== 'all' ? props.filters.status : undefined,
            q: search.value.trim() || undefined,
            ...params,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
};

let timer = null;
watch(search, () => {
    clearTimeout(timer);
    timer = setTimeout(() => visit({ page: undefined }), 300);
});

const setStatus = (key) =>
    visit({ status: key === 'all' ? undefined : key, page: undefined });
</script>

<template>
    <Head title="Sent" />

    <AppLayout>
        <PageHeader
            title="Sent"
            description="Every email sent from this workspace, with its latest delivery status."
        />

        <div class="mb-4 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div
                class="inline-flex flex-wrap rounded-full border border-zinc-800 bg-zinc-950 p-1"
            >
                <button
                    v-for="t in tabs"
                    :key="t.key"
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-full px-3.5 py-1.5 text-sm transition"
                    :class="
                        filters.status === t.key
                            ? 'bg-zinc-800 text-white'
                            : 'text-zinc-400 hover:text-zinc-200'
                    "
                    @click="setStatus(t.key)"
                >
                    {{ t.label }}
                    <span
                        class="rounded-full px-1.5 text-[11px] tabular-nums"
                        :class="
                            t.key === 'failed' && counts[t.key]
                                ? 'bg-rose-500/15 text-rose-300'
                                : 'bg-zinc-800/80 text-zinc-500'
                        "
                    >
                        {{ counts[t.key] ?? 0 }}
                    </span>
                </button>
            </div>
            <div class="relative lg:w-80">
                <Search
                    :size="16"
                    class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-zinc-500"
                />
                <input
                    v-model="search"
                    type="search"
                    placeholder="Search subject or recipient…"
                    class="md-input pl-9"
                />
            </div>
        </div>

        <div class="md-table-wrap">
            <table class="min-w-full text-left text-sm">
                <thead
                    class="border-b border-zinc-800 text-xs uppercase tracking-wide text-zinc-500"
                >
                    <tr>
                        <th class="px-4 py-3 font-medium">Recipient</th>
                        <th class="px-4 py-3 font-medium">Subject</th>
                        <th class="px-4 py-3 font-medium">Status</th>
                        <th class="px-4 py-3 text-right font-medium">Sent</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-900">
                    <tr
                        v-for="email in emails"
                        :key="email.id"
                        class="cursor-pointer transition hover:bg-white/[0.03]"
                        @click="router.visit(route('emails.show', email.id))"
                    >
                        <td class="whitespace-nowrap px-4 py-3">
                            <Link
                                :href="route('emails.show', email.id)"
                                class="font-medium text-zinc-200 hover:text-cyan-300"
                                @click.stop
                            >
                                {{ email.to || '—' }}
                            </Link>
                            <span
                                v-if="email.to_count > 1"
                                class="ml-1.5 text-xs text-zinc-500"
                            >
                                +{{ email.to_count - 1 }}
                            </span>
                        </td>
                        <td class="w-full max-w-0 px-4 py-3">
                            <div class="truncate text-zinc-300">
                                {{ email.subject }}
                            </div>
                            <div
                                v-if="email.failed && email.error"
                                class="mt-0.5 flex items-center gap-1 truncate text-xs text-rose-400/90"
                                :title="email.error"
                            >
                                <AlertCircle :size="12" class="shrink-0" />
                                <span class="truncate">{{ email.error }}</span>
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <StatusBadge :status="email.status" />
                        </td>
                        <td
                            class="whitespace-nowrap px-4 py-3 text-right"
                            :title="email.sent_at"
                        >
                            <div class="text-zinc-300">{{ email.sent_at }}</div>
                            <div class="text-xs text-zinc-500">
                                {{ email.sent_ago }}
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!emails.length">
                        <td colspan="4" class="px-4 py-16 text-center">
                            <div
                                class="mx-auto mb-3 flex h-10 w-10 items-center justify-center rounded-full bg-zinc-900 text-zinc-500"
                            >
                                <Send :size="18" />
                            </div>
                            <p class="text-zinc-400">
                                {{
                                    filters.q || filters.status !== 'all'
                                        ? 'No sent emails match your filters.'
                                        : 'Nothing sent yet.'
                                }}
                            </p>
                            <button
                                v-if="!filters.q && filters.status === 'all'"
                                type="button"
                                class="md-btn-primary mt-4"
                                @click.stop="openCompose()"
                            >
                                <PenSquare :size="16" />
                                Write your first email
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div
            v-if="pagination.total"
            class="mt-4 flex items-center justify-between text-sm text-zinc-500"
        >
            <span>
                {{ pagination.from }}–{{ pagination.to }} of
                {{ Number(pagination.total).toLocaleString() }}
            </span>
            <div class="flex items-center gap-2">
                <Link
                    v-if="pagination.prev_url"
                    :href="pagination.prev_url"
                    class="md-btn-ghost"
                    preserve-scroll
                >
                    <ChevronLeft :size="16" />
                    Newer
                </Link>
                <Link
                    v-if="pagination.next_url"
                    :href="pagination.next_url"
                    class="md-btn-ghost"
                    preserve-scroll
                >
                    Older
                    <ChevronRight :size="16" />
                </Link>
            </div>
        </div>
    </AppLayout>
</template>
