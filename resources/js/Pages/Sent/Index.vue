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

const openEmail = (id) => router.visit(route('emails.show', id));
</script>

<template>
    <Head title="Sent" />

    <AppLayout>
        <PageHeader
            title="Sent"
            description="Every email sent from this workspace, with its latest delivery status."
        />

        <div class="mb-4 space-y-3">
            <!-- Single-row scrollable status chips on mobile; wrap ok on desktop -->
            <div
                class="md-hide-scrollbar -mx-4 flex gap-2 overflow-x-auto px-4 pb-0.5 sm:-mx-6 sm:px-6 lg:mx-0 lg:flex-wrap lg:overflow-visible lg:px-0"
                data-testid="sent-status-tabs"
            >
                <button
                    v-for="t in tabs"
                    :key="t.key"
                    type="button"
                    class="inline-flex shrink-0 items-center gap-1.5 rounded-full border px-3.5 py-2 text-sm transition active:scale-[0.98]"
                    :class="
                        filters.status === t.key
                            ? 'border-cyan-400/40 bg-cyan-400/10 text-cyan-200'
                            : 'border-zinc-800 bg-zinc-950 text-zinc-400 active:bg-zinc-900'
                    "
                    :data-testid="`sent-tab-${t.key}`"
                    @click="setStatus(t.key)"
                >
                    {{ t.label }}
                    <span
                        class="rounded-full px-1.5 text-[11px] tabular-nums"
                        :class="
                            t.key === 'failed' && counts[t.key]
                                ? 'bg-rose-500/15 text-rose-300'
                                : filters.status === t.key
                                  ? 'bg-cyan-400/15 text-cyan-300'
                                  : 'bg-zinc-800/80 text-zinc-500'
                        "
                    >
                        {{ counts[t.key] ?? 0 }}
                    </span>
                </button>
            </div>

            <div class="relative">
                <Search
                    :size="16"
                    class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-zinc-500"
                />
                <input
                    v-model="search"
                    type="search"
                    placeholder="Search subject or recipient…"
                    class="md-input pl-9"
                    data-testid="sent-search"
                />
            </div>
        </div>

        <!-- Mobile list -->
        <div
            class="md-card overflow-hidden max-lg:-mx-4 max-lg:rounded-none max-lg:border-x-0 sm:max-lg:-mx-6 lg:hidden"
            data-testid="sent-mobile-list"
        >
            <button
                v-for="email in emails"
                :key="`m-${email.id}`"
                type="button"
                class="flex w-full min-w-0 items-start gap-3 border-b border-zinc-900 px-4 py-3.5 text-left transition active:bg-white/[0.04] last:border-b-0"
                @click="openEmail(email.id)"
            >
                <div class="min-w-0 flex-1 overflow-hidden">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0 flex-1">
                            <div class="truncate text-sm font-medium text-zinc-100">
                                {{ email.to || '—' }}
                                <span
                                    v-if="email.to_count > 1"
                                    class="ml-1 text-xs font-normal text-zinc-500"
                                >
                                    +{{ email.to_count - 1 }}
                                </span>
                            </div>
                            <div class="mt-0.5 truncate text-sm text-zinc-400">
                                {{ email.subject }}
                            </div>
                        </div>
                        <div class="shrink-0 text-right">
                            <div class="text-[11px] text-zinc-500" :title="email.sent_at">
                                {{ email.sent_ago || email.sent_at }}
                            </div>
                        </div>
                    </div>
                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        <StatusBadge :status="email.status" />
                        <span
                            v-if="email.failed && email.error"
                            class="flex min-w-0 items-center gap-1 text-xs text-rose-400/90"
                            :title="email.error"
                        >
                            <AlertCircle :size="12" class="shrink-0" />
                            <span class="truncate">{{ email.error }}</span>
                        </span>
                    </div>
                </div>
            </button>

            <div
                v-if="!emails.length"
                class="px-4 py-16 text-center"
                data-testid="sent-empty"
            >
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
                    @click="openCompose()"
                >
                    <PenSquare :size="16" />
                    Write your first email
                </button>
            </div>
        </div>

        <!-- Desktop table -->
        <div class="md-table-wrap hidden lg:block" data-testid="sent-desktop-table">
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
                        @click="openEmail(email.id)"
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
            class="mt-4 flex items-center justify-between gap-3 text-sm text-zinc-500"
        >
            <span class="min-w-0 truncate">
                {{ pagination.from }}–{{ pagination.to }} of
                {{ Number(pagination.total).toLocaleString() }}
            </span>
            <div class="flex shrink-0 items-center gap-2">
                <Link
                    v-if="pagination.prev_url"
                    :href="pagination.prev_url"
                    class="md-btn-ghost !px-2.5"
                    preserve-scroll
                >
                    <ChevronLeft :size="16" />
                    <span class="hidden sm:inline">Newer</span>
                </Link>
                <Link
                    v-if="pagination.next_url"
                    :href="pagination.next_url"
                    class="md-btn-ghost !px-2.5"
                    preserve-scroll
                >
                    <span class="hidden sm:inline">Older</span>
                    <ChevronRight :size="16" />
                </Link>
            </div>
        </div>
    </AppLayout>
</template>
