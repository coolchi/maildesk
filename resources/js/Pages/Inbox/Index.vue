<script setup>
import EmailFrame from '@/Components/EmailFrame.vue';
import { computed, nextTick, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import WysiwygEditor from '@/Components/WysiwygEditor.vue';
import RowActions from '@/Components/RowActions.vue';
import AttachmentList from '@/Components/AttachmentList.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { useNotifications } from '@/composables/useNotifications';
import { useToast } from '@/composables/useToast';
import {
    Archive,
    CheckCheck,
    Mail,
    MailOpen,
    AlertCircle,
    Paperclip,
    RotateCw,
    Search,
    X,
    Send,
    Star,
    Trash2,
} from '@lucide/vue';

const props = defineProps({
    threads: { type: Array, default: () => [] },
});

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

const persistRead = (thread, read) => {
    window.axios
        .patch(`/inbox/${thread.id}/read`, { read })
        .catch(() => toast.error('Could not update read state.'));
};

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
            (t.to || '').toLowerCase().includes(q) ||
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
        persistRead(thread, true);
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
        persistRead(thread, !thread.unread);
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

const sendingReply = ref(false);
const replyCc = ref('');
const replyBcc = ref('');
const showCc = ref(false);
const showBcc = ref(false);
const replyFiles = ref([]);
const fileInput = ref(null);
const messagesEl = ref(null);
const MAX_FILE = 10 * 1024 * 1024;

const formatSize = (bytes) => {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`;
    return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
};

const onPickFiles = (event) => {
    for (const file of Array.from(event.target.files || [])) {
        if (file.size > MAX_FILE) {
            toast.error(`${file.name} is larger than 10 MB.`);
            continue;
        }
        if (replyFiles.value.length >= 10) {
            toast.error('You can attach up to 10 files.');
            break;
        }
        replyFiles.value.push(file);
    }
    event.target.value = '';
};

const removeFile = (index) => replyFiles.value.splice(index, 1);

const resetReply = () => {
    replyHtml.value = '<p></p>';
    replyCc.value = '';
    replyBcc.value = '';
    showCc.value = false;
    showBcc.value = false;
    replyFiles.value = [];
};

// Keep the newest message in view: jump to the bottom when a thread opens or grows.
const scrollToLatest = () =>
    nextTick(() => {
        if (messagesEl.value) {
            messagesEl.value.scrollTop = messagesEl.value.scrollHeight;
        }
    });

watch(
    () => [activeId.value, active.value?.messages?.length],
    scrollToLatest,
    { immediate: true },
);

watch(activeId, (next, prev) => {
    if (prev !== undefined && next !== prev) resetReply();
});

const retrying = ref(null);

const retryMessage = (message) => {
    if (retrying.value) return;
    retrying.value = message.id;
    router.post(
        route('emails.retry', message.id),
        {},
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: (page) => {
                if (page.props.flash?.error) {
                    toast.error(page.props.flash.error);
                    return;
                }
                toast.success('Reply resent.');
            },
            onError: () => toast.error('Could not retry this email.'),
            onFinish: () => {
                retrying.value = null;
            },
        },
    );
};

const sendReply = () => {
    if (!active.value || sendingReply.value) return;
    const plain = replyHtml.value.replace(/<[^>]*>/g, '').trim();
    if (!plain) {
        toast.error('Write a reply before sending.');
        return;
    }

    sendingReply.value = true;
    router.post(
        `/inbox/${active.value.id}/reply`,
        {
            html: replyHtml.value,
            cc: replyCc.value.trim() || null,
            bcc: replyBcc.value.trim() || null,
            attachments: replyFiles.value,
        },
        {
            forceFormData: true,
            preserveScroll: true,
            preserveState: true,
            onSuccess: (page) => {
                if (page.props.flash?.error) {
                    toast.error(page.props.flash.error);
                    return;
                }
                resetReply();
                toast.success('Reply sent.');
            },
            onError: (errors) =>
                toast.error(
                    errors.html ??
                        errors.cc ??
                        errors.bcc ??
                        Object.values(errors)[0] ??
                        'Could not send reply.',
                ),
            onFinish: () => {
                sendingReply.value = false;
            },
        },
    );
};

// Threads are marked read only when the user opens one (selectThread), not on page load.
</script>

<template>
    <Head title="Inbox" />

    <AppLayout>
        <PageHeader title="Inbox" />

        <div
            class="md-card grid overflow-hidden lg:h-[calc(100vh-15rem)] lg:min-h-[540px] lg:grid-cols-[340px_1fr]"
            data-testid="inbox-card"
        >
            <div
                class="flex min-h-0 flex-col border-b border-zinc-800 lg:border-b-0 lg:border-r"
            >
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
                <ul class="max-h-[50vh] overflow-y-auto lg:max-h-none lg:min-h-0 lg:flex-1">
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
                                    v-if="thread.to"
                                    class="mt-0.5 truncate text-[11px] text-zinc-500"
                                    :title="`To ${thread.to}`"
                                >
                                    <span class="text-zinc-600">To</span>
                                    {{ thread.to }}
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

            <div v-if="active" class="flex min-h-0 min-w-0 flex-col">
                <div
                    class="flex items-start justify-between gap-3 border-b border-zinc-800 px-6 py-4"
                >
                    <div class="min-w-0">
                        <h2 class="text-lg font-semibold text-white">
                            {{ active.subject }}
                        </h2>
                        <p class="mt-1 text-sm text-zinc-400">
                            {{ active.from }}
                            <template v-if="active.to">
                                <span class="text-zinc-600">to</span>
                                {{ active.to }}
                            </template>
                            · {{ active.updated }}
                        </p>
                    </div>
                    <RowActions
                        :items="headerActions"
                        @select="onHeaderAction"
                    />
                </div>
                <div
                    ref="messagesEl"
                    class="space-y-4 p-6 lg:min-h-0 lg:flex-1 lg:overflow-y-auto"
                    data-testid="thread-messages"
                >
                    <article
                        v-for="message in active.messages"
                        :key="message.id || message"
                        class="rounded-xl border bg-zinc-950 p-4"
                        :class="
                            message.can_retry || message.status === 'suppressed'
                                ? 'border-rose-500/30'
                                : 'border-zinc-800'
                        "
                    >
                        <!-- Per-message sender line only matters when a thread has
                             several messages; for one message it repeats the pane header. -->
                        <div
                            v-if="active.messages.length > 1"
                            class="mb-3 flex items-center justify-between text-xs text-zinc-500"
                            data-testid="message-header"
                        >
                            <span class="truncate">
                                {{ message.from || active.from }}
                                <template v-if="message.to">
                                    <span class="text-zinc-600">to</span>
                                    {{ message.to }}
                                </template>
                            </span>
                            <span>{{ message.sent || '—' }}</span>
                        </div>
                        <div
                            v-if="message.cc || message.bcc"
                            class="-mt-2 mb-3 truncate text-xs text-zinc-500"
                        >
                            <template v-if="message.cc">
                                <span class="text-zinc-600">cc</span>
                                {{ message.cc }}
                            </template>
                            <template v-if="message.bcc">
                                <span class="ml-2 text-zinc-600">bcc</span>
                                {{ message.bcc }}
                            </template>
                        </div>
                        <EmailFrame
                            v-if="message.html"
                            :html="message.html"
                            :title="`Message from ${message.from || active.from}`"
                        />
                        <p
                            v-else
                            class="text-sm leading-relaxed text-zinc-300"
                        >
                            {{ message.text || active.snippet }}
                        </p>
                        <AttachmentList
                            v-if="message.attachments?.length"
                            class="mt-4 border-t border-zinc-800/80 pt-3"
                            :attachments="message.attachments"
                        />
                        <div
                            v-if="
                                message.direction === 'outbound' &&
                                ['failed', 'suppressed', 'bounced', 'queued', 'scheduled'].includes(message.status)
                            "
                            class="mt-3 flex flex-wrap items-center justify-between gap-2 rounded-lg px-3 py-2 text-xs"
                            :class="
                                message.can_retry || message.status !== 'queued'
                                    ? 'bg-rose-500/10 text-rose-300'
                                    : 'bg-amber-500/10 text-amber-300'
                            "
                            data-testid="delivery-state"
                        >
                            <span class="flex min-w-0 items-center gap-2">
                                <StatusBadge :status="message.status" />
                                <AlertCircle
                                    v-if="message.error"
                                    :size="13"
                                    class="shrink-0"
                                />
                                <span class="truncate" :title="message.error">
                                    {{ message.error || 'Not delivered yet.' }}
                                </span>
                            </span>
                            <button
                                v-if="message.can_retry"
                                type="button"
                                class="md-btn-ghost !px-2.5 !py-1 text-xs"
                                :disabled="retrying === message.id"
                                data-testid="retry-button"
                                @click="retryMessage(message)"
                            >
                                <RotateCw
                                    :size="13"
                                    :class="{ 'animate-spin': retrying === message.id }"
                                />
                                {{ retrying === message.id ? 'Retrying…' : 'Retry' }}
                            </button>
                        </div>
                    </article>
                </div>
                <div
                    class="shrink-0 border-t border-zinc-800 bg-zinc-950 p-4"
                    data-testid="reply-box"
                >
                    <div class="mb-2 flex items-center justify-between gap-3 text-xs">
                        <span class="truncate text-zinc-500">
                            Reply to
                            <span class="text-zinc-300">{{ active.from }}</span>
                        </span>
                        <span class="flex shrink-0 items-center gap-1">
                            <button
                                v-if="!showCc"
                                type="button"
                                class="rounded px-1.5 py-0.5 text-zinc-500 transition hover:bg-zinc-900 hover:text-zinc-200"
                                @click="showCc = true"
                            >
                                Cc
                            </button>
                            <button
                                v-if="!showBcc"
                                type="button"
                                class="rounded px-1.5 py-0.5 text-zinc-500 transition hover:bg-zinc-900 hover:text-zinc-200"
                                @click="showBcc = true"
                            >
                                Bcc
                            </button>
                        </span>
                    </div>
                    <div v-if="showCc || showBcc" class="mb-2 grid gap-2 sm:grid-cols-2">
                        <label v-if="showCc" class="relative block">
                            <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-xs text-zinc-500">Cc</span>
                            <input
                                v-model="replyCc"
                                class="md-input !py-1.5 pl-10"
                                placeholder="name@example.com, …"
                                data-testid="reply-cc"
                            />
                        </label>
                        <label v-if="showBcc" class="relative block">
                            <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-xs text-zinc-500">Bcc</span>
                            <input
                                v-model="replyBcc"
                                class="md-input !py-1.5 pl-10"
                                placeholder="name@example.com, …"
                                data-testid="reply-bcc"
                            />
                        </label>
                    </div>
                    <div class="max-h-[26vh] overflow-y-auto rounded-lg">
                        <WysiwygEditor
                            v-model="replyHtml"
                            placeholder="Write a reply…"
                            min-height="84px"
                        />
                    </div>
                    <div v-if="replyFiles.length" class="mt-2 flex flex-wrap gap-2">
                        <span
                            v-for="(file, index) in replyFiles"
                            :key="`${file.name}-${index}`"
                            class="inline-flex max-w-full items-center gap-1.5 rounded-lg border border-zinc-800 bg-zinc-900/60 py-1 pl-2 pr-1 text-xs text-zinc-300"
                        >
                            <Paperclip :size="12" class="shrink-0 text-zinc-500" />
                            <span class="max-w-[12rem] truncate">{{ file.name }}</span>
                            <span class="text-zinc-500">{{ formatSize(file.size) }}</span>
                            <button
                                type="button"
                                class="rounded p-0.5 text-zinc-500 hover:bg-zinc-800 hover:text-white"
                                :title="`Remove ${file.name}`"
                                @click="removeFile(index)"
                            >
                                <X :size="12" />
                            </button>
                        </span>
                    </div>
                    <div class="mt-3 flex items-center gap-2">
                        <button
                            type="button"
                            class="md-btn-primary"
                            :disabled="sendingReply"
                            @click="sendReply"
                        >
                            <Send :size="16" />
                            {{ sendingReply ? 'Sending…' : 'Send reply' }}
                        </button>
                        <button
                            type="button"
                            class="md-btn-ghost"
                            title="Attach files (max 10 MB each)"
                            @click="fileInput?.click()"
                        >
                            <Paperclip :size="16" />
                            Attach
                        </button>
                        <input
                            ref="fileInput"
                            type="file"
                            multiple
                            class="hidden"
                            data-testid="reply-file-input"
                            @change="onPickFiles"
                        />
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
