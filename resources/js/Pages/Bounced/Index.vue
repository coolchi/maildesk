<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import {
    AlertTriangle,
    Ban,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    Clock,
    ExternalLink,
    Eye,
    MailCheck,
    MailX,
    Search,
    Send,
    ShieldAlert,
    ShieldCheck,
    X,
} from '@lucide/vue';

const props = defineProps({
    bounces: { type: Array, default: () => [] },
    pagination: { type: Object, default: () => ({}) },
    counts: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({ type: 'all', q: '' }) },
});

const search = ref(props.filters.q || '');
const selectedId = ref(null);
const selected = computed(
    () => props.bounces.find((b) => b.id === selectedId.value) ?? null,
);

const tabs = [
    { key: 'all', label: 'All' },
    { key: 'hard', label: 'Hard' },
    { key: 'soft', label: 'Soft' },
];

const stats = computed(() => [
    { label: 'Bounced emails', value: props.counts.all ?? 0, icon: MailX, tint: 'text-zinc-300' },
    { label: 'Hard bounces', value: props.counts.hard ?? 0, icon: Ban, tint: 'text-rose-300' },
    { label: 'Soft bounces', value: props.counts.soft ?? 0, icon: AlertTriangle, tint: 'text-amber-300' },
    { label: 'Suppressed addresses', value: props.counts.suppressed ?? 0, icon: ShieldAlert, tint: 'text-cyan-300' },
]);

const visit = (params) => {
    router.get(
        route('bounced'),
        {
            type: props.filters.type !== 'all' ? props.filters.type : undefined,
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

const setType = (key) =>
    visit({ type: key === 'all' ? undefined : key, page: undefined });

const eventMeta = {
    sent: { label: 'Sent', icon: Send, dot: 'bg-cyan-400', text: 'text-cyan-300' },
    delivered: { label: 'Delivered', icon: MailCheck, dot: 'bg-emerald-400', text: 'text-emerald-300' },
    bounced: { label: 'Bounced', icon: MailX, dot: 'bg-rose-400', text: 'text-rose-300' },
    complained: { label: 'Marked as spam', icon: ShieldAlert, dot: 'bg-rose-400', text: 'text-rose-300' },
    opened: { label: 'Opened', icon: Eye, dot: 'bg-sky-400', text: 'text-sky-300' },
    delivery_delayed: { label: 'Delivery delayed', icon: Clock, dot: 'bg-amber-400', text: 'text-amber-300' },
    suppressed: { label: 'Suppressed', icon: Ban, dot: 'bg-zinc-400', text: 'text-zinc-300' },
};

const describe = (event) =>
    eventMeta[event.type] ?? {
        label: String(event.type).replace(/[._]/g, ' '),
        icon: CheckCircle2,
        dot: 'bg-zinc-500',
        text: 'text-zinc-300',
    };

const onKey = (e) => {
    if (e.key === 'Escape') selectedId.value = null;
};
onMounted(() => window.addEventListener('keydown', onKey));
onBeforeUnmount(() => window.removeEventListener('keydown', onKey));
</script>

<template>
    <Head title="Bounced" />

    <AppLayout>
        <PageHeader
            title="Bounced"
            description="Emails the receiving server rejected, why they bounced, and whether the address is now suppressed."
        />

        <div class="mb-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <div v-for="s in stats" :key="s.label" class="md-card flex items-center gap-3 p-4">
                <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-zinc-900" :class="s.tint">
                    <component :is="s.icon" :size="17" />
                </div>
                <div>
                    <div class="text-xl font-semibold tabular-nums text-white">
                        {{ Number(s.value).toLocaleString() }}
                    </div>
                    <div class="text-xs text-zinc-500">{{ s.label }}</div>
                </div>
            </div>
        </div>

        <div class="mb-4 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div class="inline-flex flex-wrap rounded-full border border-zinc-800 bg-zinc-950 p-1">
                <button
                    v-for="t in tabs"
                    :key="t.key"
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-full px-3.5 py-1.5 text-sm transition"
                    :class="filters.type === t.key ? 'bg-zinc-800 text-white' : 'text-zinc-400 hover:text-zinc-200'"
                    @click="setType(t.key)"
                >
                    {{ t.label }}
                    <span class="rounded-full bg-zinc-800/80 px-1.5 text-[11px] tabular-nums text-zinc-500">
                        {{ counts[t.key] ?? 0 }}
                    </span>
                </button>
            </div>
            <div class="relative lg:w-80">
                <Search :size="16" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-zinc-500" />
                <input
                    v-model="search"
                    type="search"
                    placeholder="Search recipient, subject or reason…"
                    class="md-input pl-9"
                />
            </div>
        </div>

        <div class="md-table-wrap">
            <table class="min-w-full text-left text-sm">
                <thead class="border-b border-zinc-800 text-xs uppercase tracking-wide text-zinc-500">
                    <tr>
                        <th class="px-4 py-3 font-medium">Recipient</th>
                        <th class="px-4 py-3 font-medium">Subject</th>
                        <th class="px-4 py-3 font-medium">Type</th>
                        <th class="px-4 py-3 font-medium">Reason</th>
                        <th class="px-4 py-3 font-medium">Suppressed</th>
                        <th class="px-4 py-3 text-right font-medium">Bounced</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-900">
                    <tr
                        v-for="b in bounces"
                        :key="b.id"
                        class="cursor-pointer transition hover:bg-white/[0.03]"
                        :class="{ 'bg-white/[0.03]': selectedId === b.id }"
                        data-testid="bounce-row"
                        @click="selectedId = b.id"
                    >
                        <td class="whitespace-nowrap px-4 py-3">
                            <span class="font-medium text-zinc-200">{{ b.to || '—' }}</span>
                            <span v-if="b.to_count > 1" class="ml-1.5 text-xs text-zinc-500">+{{ b.to_count - 1 }}</span>
                        </td>
                        <td class="max-w-[16rem] px-4 py-3">
                            <div class="truncate text-zinc-300">{{ b.subject }}</div>
                        </td>
                        <td class="px-4 py-3">
                            <span
                                class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium capitalize ring-1 ring-inset"
                                :class="b.type === 'hard'
                                    ? 'bg-rose-500/10 text-rose-300 ring-rose-500/20'
                                    : 'bg-amber-500/10 text-amber-300 ring-amber-500/20'"
                            >
                                {{ b.type }}
                            </span>
                        </td>
                        <td class="w-full max-w-0 px-4 py-3">
                            <div class="truncate text-zinc-400" :title="b.reason">{{ b.reason }}</div>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3">
                            <span
                                v-if="b.suppressed"
                                class="inline-flex items-center gap-1 text-xs text-cyan-300"
                            >
                                <ShieldAlert :size="13" /> Yes
                            </span>
                            <span v-else class="inline-flex items-center gap-1 text-xs text-zinc-500">
                                <ShieldCheck :size="13" /> No
                            </span>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-right" :title="b.bounced_at">
                            <div class="text-zinc-300">{{ b.bounced_at }}</div>
                            <div class="text-xs text-zinc-500">{{ b.bounced_ago }}</div>
                        </td>
                    </tr>
                    <tr v-if="!bounces.length">
                        <td colspan="6" class="px-4 py-16 text-center">
                            <div class="mx-auto mb-3 flex h-10 w-10 items-center justify-center rounded-full bg-zinc-900 text-emerald-400">
                                <MailCheck :size="18" />
                            </div>
                            <p class="text-zinc-400">
                                {{ filters.q || filters.type !== 'all'
                                    ? 'No bounces match your filters.'
                                    : 'No bounces. Every email so far has been accepted.' }}
                            </p>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="pagination.total" class="mt-4 flex items-center justify-between text-sm text-zinc-500">
            <span>
                {{ pagination.from }}–{{ pagination.to }} of {{ Number(pagination.total).toLocaleString() }}
            </span>
            <div class="flex items-center gap-2">
                <Link v-if="pagination.prev_url" :href="pagination.prev_url" class="md-btn-ghost" preserve-scroll>
                    <ChevronLeft :size="16" /> Newer
                </Link>
                <Link v-if="pagination.next_url" :href="pagination.next_url" class="md-btn-ghost" preserve-scroll>
                    Older <ChevronRight :size="16" />
                </Link>
            </div>
        </div>

        <Teleport to="body">
            <Transition
                enter-active-class="transition duration-200"
                enter-from-class="opacity-0"
                leave-active-class="transition duration-150"
                leave-to-class="opacity-0"
            >
                <div v-if="selected" class="fixed inset-0 z-[90] bg-black/50" @click="selectedId = null" />
            </Transition>
            <Transition
                enter-active-class="transition duration-200 ease-out"
                enter-from-class="translate-x-full"
                leave-active-class="transition duration-150 ease-in"
                leave-to-class="translate-x-full"
            >
                <aside
                    v-if="selected"
                    class="fixed inset-y-0 right-0 z-[100] flex w-full max-w-md flex-col border-l border-zinc-800 bg-zinc-950 shadow-2xl"
                    data-testid="bounce-drawer"
                >
                    <div class="flex items-start justify-between gap-3 border-b border-zinc-800 px-5 py-4">
                        <div class="min-w-0">
                            <div class="text-xs uppercase tracking-wide text-zinc-500">Bounced email</div>
                            <h2 class="mt-1 truncate text-base font-semibold text-white">{{ selected.subject }}</h2>
                            <div class="mt-0.5 truncate text-sm text-zinc-400">to {{ selected.to }}</div>
                        </div>
                        <button type="button" class="rounded-lg p-1.5 text-zinc-500 hover:bg-zinc-900 hover:text-white" @click="selectedId = null">
                            <X :size="18" />
                        </button>
                    </div>

                    <div class="flex-1 space-y-5 overflow-y-auto px-5 py-5">
                        <div class="grid grid-cols-2 gap-3 text-sm">
                            <div class="rounded-lg border border-zinc-800 p-3">
                                <div class="text-xs text-zinc-500">Type</div>
                                <div class="mt-1 font-medium capitalize" :class="selected.type === 'hard' ? 'text-rose-300' : 'text-amber-300'">
                                    {{ selected.type }} bounce
                                </div>
                                <div v-if="selected.provider_type" class="text-xs text-zinc-500">Provider says “{{ selected.provider_type }}”</div>
                            </div>
                            <div class="rounded-lg border border-zinc-800 p-3">
                                <div class="text-xs text-zinc-500">Status</div>
                                <div class="mt-1"><StatusBadge :status="selected.status" /></div>
                            </div>
                        </div>

                        <div>
                            <div class="mb-1.5 text-xs text-zinc-500">Reason</div>
                            <p class="rounded-lg border border-zinc-800 bg-zinc-900/60 p-3 text-sm leading-relaxed text-zinc-300">
                                {{ selected.reason }}
                            </p>
                        </div>

                        <div class="rounded-lg border p-3 text-sm" :class="selected.suppressed ? 'border-cyan-500/30 bg-cyan-500/5' : 'border-zinc-800'">
                            <div class="flex items-center gap-2 font-medium" :class="selected.suppressed ? 'text-cyan-300' : 'text-zinc-300'">
                                <component :is="selected.suppressed ? ShieldAlert : ShieldCheck" :size="15" />
                                {{ selected.suppressed ? 'Added to the suppression list' : 'Not suppressed' }}
                            </div>
                            <p class="mt-1 text-xs text-zinc-500">
                                <template v-if="selected.suppression">
                                    {{ selected.suppression.email }} since {{ selected.suppression.since }}.
                                    Future sends to it are blocked.
                                </template>
                                <template v-else>
                                    {{ selected.type === 'soft' ? 'MailDesk will keep sending to this address. Soft bounces are usually temporary.' : 'MailDesk will still send to this address. Add it to the suppression list if it keeps bouncing.' }}
                                </template>
                            </p>
                        </div>

                        <div>
                            <div class="mb-3 text-xs text-zinc-500">Event history</div>
                            <ol class="relative ml-2 border-l border-zinc-800">
                                <li v-for="(event, i) in selected.events" :key="i" class="relative pb-5 pl-5 last:pb-0" data-testid="bounce-event">
                                    <span class="absolute -left-[5px] top-1.5 h-2.5 w-2.5 rounded-full ring-4 ring-zinc-950" :class="describe(event).dot" />
                                    <div class="flex items-center gap-1.5 text-sm font-medium capitalize" :class="describe(event).text">
                                        <component :is="describe(event).icon" :size="14" />
                                        {{ describe(event).label }}
                                        <span v-if="event.bounce_type" class="text-xs font-normal normal-case text-zinc-500">({{ event.bounce_type }})</span>
                                    </div>
                                    <div class="text-xs text-zinc-500">{{ event.at || 'Time unknown' }}</div>
                                    <p v-if="event.reason" class="mt-1 text-xs leading-relaxed text-zinc-400">{{ event.reason }}</p>
                                </li>
                            </ol>
                        </div>
                    </div>

                    <div class="border-t border-zinc-800 px-5 py-3">
                        <Link :href="route('emails.show', selected.id)" class="md-btn-ghost w-full justify-center">
                            <ExternalLink :size="15" /> Open full email
                        </Link>
                    </div>
                </aside>
            </Transition>
        </Teleport>
    </AppLayout>
</template>
