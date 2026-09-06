<script setup>
import { computed, ref, watch } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import WysiwygEditor from '@/Components/WysiwygEditor.vue';
import RowActions from '@/Components/RowActions.vue';
import { useComposeModal } from '@/composables/useComposeModal';
import { useNotifications } from '@/composables/useNotifications';
import { useToast } from '@/composables/useToast';
import {
    Archive,
    CheckCheck,
    Mail,
    MailOpen,
    PenSquare,
    Search,
    Send,
    Star,
    Trash2,
} from '@lucide/vue';

const props = defineProps({
    threads: { type: Array, default: () => [] },
});

const { open: openCompose } = useComposeModal();
const { markThreadRead, setInboxUnread } = useNotifications();
const toast = useToast();
const search = ref('');
const threads = ref(props.threads.map((t) => ({ ...t })));
const activeId = ref(threads.value[0]?.id ?? null);
const replyHtml = ref('<p></p>');

watch(
    () => props.threads,
    (value) => {
        threads.value = value.map((t) => ({ ...t }));
        if (!threads.value.find((t) => t.id === activeId.value)) {
            activeId.value = threads.value[0]?.id ?? null;
        }
        syncInboxBadge();
    },
);

const syncInboxBadge = () => {
    setInboxUnread(threads.value.filter((t) => t.unread).length);
};

syncInboxBadge();

const filtered = computed(() =>
    threads.value.filter((t) => {
        const q = search.value.trim().toLowerCase();
        return (
            !q ||
            t.subject.toLowerCase().includes(q) ||
            t.from.toLowerCase().includes(q) ||
            t.snippet.toLowerCase().includes(q)
        );
    }),
);

const active = computed(
    () => threads.value.find((t) => t.id === activeId.value) ?? null,
);

const selectThread = (thread) => {
    activeId.value = thread.id;
    if (thread.unread) {
        thread.unread = false;
        markThreadRead(thread.id);
        syncInboxBadge();
    }
};

const threadActions = (thread) => [
    {
        id: 'read',
        label: thread.unread ? 'Mark as read' : 'Mark as unread',
        icon: thread.unread ? MailOpen : Mail,
    },
    { id: 'star', label: 'Star conversation', icon: Star },
    { id: 'archive', label: 'Archive', icon: Archive },
    { id: 'delete', label: 'Delete', icon: Trash2, danger: true },
];

const onThreadAction = (thread, item) => {
    if (item.id === 'read') {
        thread.unread = !thread.unread;
        if (!thread.unread) markThreadRead(thread.id);
        syncInboxBadge();
        toast.success(thread.unread ? 'Marked unread.' : 'Marked read.');
    } else if (item.id === 'star') {
        toast.success('Starred.');
    } else if (item.id === 'archive') {
        threads.value = threads.value.filter((t) => t.id !== thread.id);
        if (activeId.value === thread.id) {
            activeId.value = threads.value[0]?.id ?? null;
        }
        syncInboxBadge();
        toast.success('Archived.');
    } else if (item.id === 'delete') {
        threads.value = threads.value.filter((t) => t.id !== thread.id);
        if (activeId.value === thread.id) {
            activeId.value = threads.value[0]?.id ?? null;
        }
        syncInboxBadge();
        toast.info('Conversation deleted.');
    }
};

const headerActions = computed(() => [
    {
        id: 'read',
        label: active.value?.unread ? 'Mark as read' : 'Mark as unread',
        icon: CheckCheck,
    },
    { id: 'archive', label: 'Archive', icon: Archive },
    { id: 'delete', label: 'Delete', icon: Trash2, danger: true },
]);

const onHeaderAction = (item) => {
    if (!active.value) return;
    onThreadAction(active.value, item);
};

const sendReply = () => {
    toast.info('Reply sending will use your workspace provider next.');
    replyHtml.value = '<p></p>';
};

watch(
    () => active.value,
    (thread) => {
        if (thread?.unread) {
            thread.unread = false;
            markThreadRead(thread.id);
            syncInboxBadge();
        }
    },
    { immediate: true },
);
</script>

<template>
    <Head title="Inbox" />

    <AppLayout>
        <PageHeader title="Inbox">
            <template #actions>
                <button
                    type="button"
                    class="md-btn-primary"
                    @click="openCompose()"
                >
                    <PenSquare :size="16" />
                    Compose
                </button>
            </template>
        </PageHeader>

        <div
            class="md-card grid min-h-[70vh] overflow-hidden lg:grid-cols-[340px_1fr]"
        >
            <div class="border-b border-zinc-800 lg:border-b-0 lg:border-r">
                <div class="relative border-b border-zinc-800 p-3">
                    <Search
                        :size="14"
                        class="pointer-events-none absolute left-6 top-1/2 -translate-y-1/2 text-zinc-500"
                    />
                    <input
                        v-model="search"
                        type="search"
                        placeholder="Search inbox…"
                        class="md-input pl-9"
                    />
                </div>
                <ul
                    class="max-h-[60vh] overflow-y-auto lg:max-h-[calc(70vh-57px)]"
                >
                    <li v-for="thread in filtered" :key="thread.id">
                        <div
                            class="group flex items-start border-b border-zinc-900 transition hover:bg-white/[0.03]"
                            :class="{
                                'bg-zinc-900/80': activeId === thread.id,
                            }"
                        >
                            <button
                                type="button"
                                class="min-w-0 flex-1 px-4 py-3 text-left"
                                @click="selectThread(thread)"
                            >
                                <div
                                    class="flex items-center justify-between gap-2"
                                >
                                    <span
                                        class="flex min-w-0 items-center gap-2"
                                    >
                                        <span
                                            v-if="thread.unread"
                                            class="h-1.5 w-1.5 shrink-0 rounded-full bg-cyan-400"
                                        />
                                        <span
                                            class="truncate text-sm"
                                            :class="
                                                thread.unread
                                                    ? 'font-semibold text-white'
                                                    : 'text-zinc-300'
                                            "
                                        >
                                            {{ thread.from }}
                                        </span>
                                    </span>
                                    <span
                                        class="shrink-0 text-[11px] text-zinc-500"
                                    >
                                        {{ thread.updated }}
                                    </span>
                                </div>
                                <div
                                    class="mt-1 truncate text-sm"
                                    :class="
                                        thread.unread
                                            ? 'font-medium text-zinc-100'
                                            : 'text-zinc-400'
                                    "
                                >
                                    {{ thread.subject }}
                                </div>
                                <div
                                    class="mt-0.5 truncate text-xs text-zinc-500"
                                >
                                    {{ thread.snippet }}
                                </div>
                            </button>
                            <div
                                class="pr-2 pt-2 opacity-0 transition group-hover:opacity-100"
                            >
                                <RowActions
                                    :items="threadActions(thread)"
                                    @select="onThreadAction(thread, $event)"
                                />
                            </div>
                        </div>
                    </li>
                    <li
                        v-if="!filtered.length"
                        class="px-4 py-10 text-center text-sm text-zinc-500"
                    >
                        No conversations found.
                    </li>
                </ul>
            </div>

            <div v-if="active" class="flex flex-col">
                <div
                    class="flex items-start justify-between gap-3 border-b border-zinc-800 px-6 py-4"
                >
                    <div class="min-w-0">
                        <h2 class="text-lg font-semibold text-white">
                            {{ active.subject }}
                        </h2>
                        <p class="mt-1 text-sm text-zinc-400">
                            {{ active.from }} · {{ active.updated }}
                        </p>
                    </div>
                    <RowActions
                        :items="headerActions"
                        @select="onHeaderAction"
                    />
                </div>
                <div class="flex-1 space-y-4 overflow-y-auto p-6">
                    <article
                        v-for="message in active.messages"
                        :key="message.id || message"
                        class="rounded-xl border border-zinc-800 bg-zinc-950 p-4"
                    >
                        <div
                            class="mb-3 flex items-center justify-between text-xs text-zinc-500"
                        >
                            <span>{{ message.from || active.from }}</span>
                            <span>{{ message.sent || '—' }}</span>
                        </div>
                        <div
                            v-if="message.html"
                            class="prose prose-invert max-w-none text-sm leading-relaxed text-zinc-300"
                            v-html="message.html"
                        />
                        <p
                            v-else
                            class="text-sm leading-relaxed text-zinc-300"
                        >
                            {{ message.text || active.snippet }}
                        </p>
                    </article>
                </div>
                <div class="border-t border-zinc-800 p-4">
                    <WysiwygEditor
                        v-model="replyHtml"
                        placeholder="Write a reply…"
                        min-height="140px"
                    />
                    <div class="mt-3 flex justify-end">
                        <button
                            type="button"
                            class="md-btn-primary"
                            @click="sendReply"
                        >
                            <Send :size="16" />
                            Send reply
                        </button>
                    </div>
                </div>
            </div>
            <div
                v-else
                class="flex items-center justify-center p-12 text-sm text-zinc-500"
            >
                Select a conversation
            </div>
        </div>
    </AppLayout>
</template>
