<script setup>
import EmailFrame from '@/Components/EmailFrame.vue';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import WysiwygEditor from '@/Components/WysiwygEditor.vue';
import RowActions from '@/Components/RowActions.vue';
import AttachmentList from '@/Components/AttachmentList.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { useNotifications } from '@/composables/useNotifications';
import { useInboxPageLive } from '@/composables/useInboxLive';
import { useToast } from '@/composables/useToast';
import { useMobileChrome } from '@/composables/useMobileChrome';
import {
    Archive,
    ArrowLeft,
    CheckCheck,
    Mail,
    MailOpen,
    AlertCircle,
    Paperclip,
    Forward,
    Reply,
    Sparkles,
    UsersRound,
    RotateCw,
    Search,
    X,
    Send,
    Trash2,
    RotateCcw,
} from '@lucide/vue';

const props = defineProps({
    signatureEnabled: { type: Boolean, default: false },
    replyDraftEnabled: { type: Boolean, default: false },
    folder: { type: String, default: 'inbox' },
    threads: { type: Array, default: () => [] },
    trashRetentionDays: { type: Number, default: 30 },
});

const page = usePage();
const { markThreadRead, setInboxUnread, inboxUnread } = useNotifications();
useInboxPageLive();
const toast = useToast();
const { setHideMobileHeader } = useMobileChrome();
const canManage = computed(() => Boolean(page.props.auth?.abilities?.manage));

const folderLinks = computed(() => [
    { id: 'inbox', label: 'Inbox', href: route('inbox') },
    { id: 'archive', label: 'Archive', href: route('archive') },
    { id: 'trash', label: 'Trash', href: route('trash') },
]);
const search = ref('');
const threads = ref(props.threads.map((t) => ({ ...t })));
/** On mobile, start on the list — don't auto-open a thread under it. */
const isNarrow = () =>
    typeof window !== 'undefined' &&
    window.matchMedia('(max-width: 1023px)').matches;
const activeId = ref(isNarrow() ? null : (threads.value[0]?.id ?? null));
const mobileDetail = ref(false);
const replyHtml = ref('<p></p>');

const syncMobileChrome = () => {
    setHideMobileHeader(Boolean(mobileDetail.value && activeId.value && isNarrow()));
};

const syncViewportMode = () => {
    if (!isNarrow()) {
        mobileDetail.value = false;
        if (!activeId.value && threads.value[0]) {
            activeId.value = threads.value[0].id;
        }
        syncMobileChrome();
        return;
    }
    if (!mobileDetail.value) {
        // Keep list-first on phone; clear selection so the pane stays hidden.
        activeId.value = null;
    }
    syncMobileChrome();
};

onMounted(() => {
    syncViewportMode();
    window.addEventListener('resize', syncViewportMode);
});

onBeforeUnmount(() => {
    window.removeEventListener('resize', syncViewportMode);
    setHideMobileHeader(false);
});

watch(mobileDetail, syncMobileChrome);

watch(
    () => props.threads,
    (value) => {
        threads.value = value.map((t) => ({ ...t }));
        if (!threads.value.find((t) => t.id === activeId.value)) {
            activeId.value = isNarrow()
                ? null
                : (threads.value[0]?.id ?? null);
            if (isNarrow()) {
                mobileDetail.value = false;
            }
        }
        // After live reload, prefer the shared unread count — the thread list
        // is capped and must not overwrite the sidebar badge.
        if (typeof page.props.inbox_unread === 'number') {
            setInboxUnread(page.props.inbox_unread);
        }
    },
);

const persistRead = (thread, read) => {
    window.axios
        .patch(`/inbox/${thread.id}/read`, { read })
        .then(({ data }) => {
            if (typeof data?.inbox_unread === 'number') {
                setInboxUnread(data.inbox_unread);
            }
        })
        .catch(() => toast.error('Could not update read state.'));
};

/** Optimistic local badge adjust while waiting for the read API. */
const syncInboxBadge = (delta = null) => {
    if (typeof delta === 'number') {
        setInboxUnread(inboxUnread.value + delta);
        return;
    }
    if (props.folder !== 'inbox') {
        return;
    }
    setInboxUnread(threads.value.filter((t) => t.unread).length);
};

const filtered = computed(() =>
    threads.value.filter((t) => {
        const q = search.value.trim().toLowerCase();
        return (
            !q ||
            t.subject.toLowerCase().includes(q) ||
            t.from.toLowerCase().includes(q) ||
            (t.from_name || '').toLowerCase().includes(q) ||
            (t.to || '').toLowerCase().includes(q) ||
            t.snippet.toLowerCase().includes(q)
        );
    }),
);

const threadFromLabel = (thread) =>
    (thread?.from_name || '').trim() || thread?.from_email || thread?.from || 'Unknown';

const threadFromEmail = (thread) =>
    thread?.from_email || thread?.from || '';

const active = computed(
    () => threads.value.find((t) => t.id === activeId.value) ?? null,
);

const markThreadAsRead = (thread) => {
    if (!thread?.unread) {
        return;
    }
    thread.unread = false;
    markThreadRead(thread.id);
    persistRead(thread, true);
    syncInboxBadge(-1);
};

const selectThread = (thread) => {
    activeId.value = thread.id;
    if (isNarrow()) {
        mobileDetail.value = true;
        syncMobileChrome();
    }
};

const closeMobileDetail = () => {
    mobileDetail.value = false;
    activeId.value = null;
    syncMobileChrome();
};

// Mark read once the detail pane is showing this thread's body (including the
// auto-selected first thread on load) — not merely on list click.
watch(
    () => [activeId.value, active.value?.messages?.length ?? 0],
    () => {
        const thread = active.value;
        if (!thread?.unread) {
            return;
        }
        if (!(thread.messages?.length > 0 || thread.snippet)) {
            return;
        }
        markThreadAsRead(thread);
    },
    { immediate: true },
);

const threadActions = (thread) => {
    const actions = [
        {
            id: 'read',
            label: thread.unread ? 'Mark as read' : 'Mark as unread',
            icon: thread.unread ? MailOpen : Mail,
        },
    ];

    if (props.folder === 'trash') {
        actions.push(
            { id: 'restore', label: 'Restore', icon: RotateCcw },
            { id: 'destroy', label: 'Delete forever', icon: Trash2, danger: true },
        );
        return actions;
    }

    actions.push(
        {
            id: 'archive',
            label: props.folder === 'archive' ? 'Move to inbox' : 'Archive',
            icon: Archive,
        },
        { id: 'trash', label: 'Move to trash', icon: Trash2, danger: true },
    );

    return actions;
};

const removeThreadFromList = (thread) => {
    const wasUnread = thread.unread;
    threads.value = threads.value.filter((t) => t.id !== thread.id);
    if (activeId.value === thread.id) {
        if (isNarrow()) {
            mobileDetail.value = false;
            activeId.value = null;
        } else {
            activeId.value = threads.value[0]?.id ?? null;
        }
    }
    if (props.folder === 'inbox' && wasUnread) {
        syncInboxBadge(-1);
    } else if (typeof page.props.inbox_unread === 'number') {
        setInboxUnread(page.props.inbox_unread);
    }
};

const onThreadAction = (thread, item) => {
    if (item.id === 'read') {
        const wasUnread = thread.unread;
        thread.unread = !thread.unread;
        if (!thread.unread) markThreadRead(thread.id);
        persistRead(thread, !thread.unread);
        syncInboxBadge(wasUnread ? -1 : 1);
        toast.success(thread.unread ? 'Marked unread.' : 'Marked read.');
    } else if (item.id === 'archive') {
        router.post(
            route('inbox.archive', thread.id),
            {},
            {
                preserveScroll: true,
                onSuccess: () => {
                    removeThreadFromList(thread);
                    toast.success(
                        props.folder === 'archive'
                            ? 'Moved to inbox.'
                            : 'Archived.',
                    );
                },
                onError: () => toast.error('Could not update conversation.'),
            },
        );
    } else if (item.id === 'trash' || item.id === 'restore') {
        router.post(
            route('inbox.trash', thread.id),
            {},
            {
                preserveScroll: true,
                onSuccess: () => {
                    removeThreadFromList(thread);
                    toast.success(
                        item.id === 'restore' ? 'Restored.' : 'Moved to trash.',
                    );
                },
                onError: () => toast.error('Could not update conversation.'),
            },
        );
    } else if (item.id === 'destroy') {
        if (
            !window.confirm(
                'Delete this conversation forever? This cannot be undone.',
            )
        ) {
            return;
        }
        router.delete(route('inbox.destroy', thread.id), {
            preserveScroll: true,
            onSuccess: () => {
                removeThreadFromList(thread);
                toast.success('Deleted forever.');
            },
            onError: () => toast.error('Could not delete conversation.'),
        });
    }
};

const folderTitle = computed(() => {
    if (props.folder === 'archive') return 'Archive';
    if (props.folder === 'trash') return 'Trash';
    return 'Inbox';
});

const folderDescription = computed(() => {
    if (props.folder !== 'trash') {
        return '';
    }
    const days = props.trashRetentionDays || 30;
    return `Conversations stay here for ${days} days, then are deleted forever.`;
});

const emptyTrash = () => {
    if (!threads.value.length) {
        toast.error('Trash is already empty.');
        return;
    }
    if (
        !window.confirm(
            `Empty trash? ${threads.value.length} conversation${threads.value.length === 1 ? '' : 's'} will be deleted forever.`,
        )
    ) {
        return;
    }
    router.delete(route('trash.empty'), {
        onSuccess: () => {
            threads.value = [];
            activeId.value = null;
            toast.success('Trash emptied.');
        },
        onError: () => toast.error('Could not empty trash.'),
    });
};

const headerActions = computed(() => {
    if (props.folder === 'trash') {
        return [
            {
                id: 'read',
                label: active.value?.unread ? 'Mark as read' : 'Mark as unread',
                icon: CheckCheck,
            },
            { id: 'restore', label: 'Restore', icon: RotateCcw },
            { id: 'destroy', label: 'Delete forever', icon: Trash2, danger: true },
        ];
    }

    return [
        {
            id: 'read',
            label: active.value?.unread ? 'Mark as read' : 'Mark as unread',
            icon: CheckCheck,
        },
        {
            id: 'archive',
            label: props.folder === 'archive' ? 'Move to inbox' : 'Archive',
            icon: Archive,
        },
        { id: 'trash', label: 'Move to trash', icon: Trash2, danger: true },
    ];
});

const onHeaderAction = (item) => {
    if (!active.value) return;
    onThreadAction(active.value, item);
};

const sendingReply = ref(false);
const suggestingReply = ref(false);
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

const replyOpen = ref(false);
// "reply" answers the sender; "forward" sends the latest message on to someone new.
const replyMode = ref('reply');
const forwardTo = ref('');

const openReply = () => {
    replyMode.value = 'reply';
    replyOpen.value = true;
    scrollToLatest();
    nextTick(() => document.querySelector('[data-testid=reply-box] .ProseMirror')?.focus());
};

const openForward = () => {
    replyMode.value = 'forward';
    replyOpen.value = true;
    scrollToLatest();
    nextTick(() => document.querySelector('[data-testid=forward-to]')?.focus());
};

const closeReply = () => {
    replyOpen.value = false;
};

// "r" opens the reply box and "f" forwards, like Gmail. Ignored while typing in a field.
const onKeydown = (event) => {
    if (props.folder === 'trash') return;
    if (!['r', 'f'].includes(event.key) || event.metaKey || event.ctrlKey || event.altKey) return;
    const el = event.target;
    if (el?.closest?.('input, textarea, select, [contenteditable="true"]')) return;
    if (!active.value) return;
    event.preventDefault();
    if (event.key === 'f') openForward();
    else openReply();
};
onMounted(() => window.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown));

const resetReply = () => {
    replyOpen.value = false;
    replyMode.value = 'reply';
    forwardTo.value = '';
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

const sendForward = () => {
    if (!active.value || sendingReply.value) return;
    if (!forwardTo.value.trim()) {
        toast.error('Add at least one recipient to forward to.');
        return;
    }

    sendingReply.value = true;
    router.post(
        route('inbox.forward', active.value.id),
        {
            to: forwardTo.value.trim(),
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
                toast.success('Message forwarded.');
            },
            onError: (errors) =>
                toast.error(errors.to ?? Object.values(errors)[0] ?? 'Could not forward.'),
            onFinish: () => {
                sendingReply.value = false;
            },
        },
    );
};

const sendReply = () => {
    if (replyMode.value === 'forward') {
        sendForward();
        return;
    }
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

const suggestReply = async () => {
    if (!active.value || suggestingReply.value || replyMode.value !== 'reply') {
        return;
    }

    suggestingReply.value = true;
    try {
        const { data } = await window.axios.post(route('inbox.suggest-reply', active.value.id));
        if (data?.html) {
            replyHtml.value = data.html;
            if (!replyOpen.value) {
                openReply();
            }
            toast.success('Draft inserted — review before sending.');
        } else {
            toast.error('No draft was returned.');
        }
    } catch (error) {
        const message =
            error?.response?.data?.message ||
            error?.response?.data?.error ||
            'Could not suggest a reply.';
        toast.error(message);
    } finally {
        suggestingReply.value = false;
    }
};

const avatarTones = [
    'bg-cyan-400/20 text-cyan-300',
    'bg-violet-400/20 text-violet-300',
    'bg-emerald-400/20 text-emerald-300',
    'bg-amber-400/20 text-amber-300',
    'bg-rose-400/20 text-rose-300',
    'bg-sky-400/20 text-sky-300',
    'bg-fuchsia-400/20 text-fuchsia-300',
    'bg-teal-400/20 text-teal-300',
];

const avatarInitials = (thread) => {
    const name = (thread?.from_name || '').trim();
    if (name) {
        const parts = name.split(/\s+/).filter(Boolean);
        if (parts.length >= 2) {
            return `${parts[0][0]}${parts[1][0]}`.toUpperCase();
        }
        return name.slice(0, 2).toUpperCase();
    }
    const raw = String(thread?.from_email || thread?.from || '').trim();
    if (!raw) {
        return '?';
    }
    const local = raw.includes('@') ? raw.split('@')[0] : raw;
    const parts = local
        .replace(/[._+\-]+/g, ' ')
        .trim()
        .split(/\s+/)
        .filter(Boolean);
    if (parts.length >= 2) {
        return `${parts[0][0]}${parts[1][0]}`.toUpperCase();
    }
    return local.slice(0, 2).toUpperCase();
};

const avatarTone = (thread) => {
    const value = String(thread?.from_email || thread?.from_name || thread?.from || '');
    let hash = 0;
    for (let i = 0; i < value.length; i += 1) {
        hash = (hash + value.charCodeAt(i) * (i + 1)) % avatarTones.length;
    }
    return avatarTones[hash];
};
</script>

<template>
    <Head :title="folderTitle" />

    <AppLayout>
        <div class="hidden lg:block">
            <PageHeader :title="folderTitle" :description="folderDescription">
                <template #actions>
                    <div
                        v-if="canManage"
                        class="inline-flex rounded-lg border border-zinc-800 bg-zinc-950/80 p-0.5"
                        data-testid="admin-folder-switcher"
                    >
                        <Link
                            v-for="link in folderLinks"
                            :key="link.id"
                            :href="link.href"
                            class="rounded-md px-3 py-1.5 text-xs font-medium transition"
                            :class="
                                folder === link.id
                                    ? 'bg-zinc-800 text-white'
                                    : 'text-zinc-400 hover:text-zinc-200'
                            "
                        >
                            {{ link.label }}
                        </Link>
                    </div>
                    <button
                        v-if="folder === 'trash'"
                        type="button"
                        class="md-btn-ghost !border-rose-500/30 !text-rose-300 hover:!border-rose-400/50 hover:!text-rose-200"
                        data-testid="empty-trash"
                        :disabled="!threads.length"
                        @click="emptyTrash"
                    >
                        <Trash2 :size="14" />
                        Empty trash
                    </button>
                </template>
            </PageHeader>
        </div>

        <div
            v-if="canManage"
            class="mb-3 flex items-center gap-2 px-1 lg:hidden"
            data-testid="admin-folder-switcher-mobile"
        >
            <div
                class="inline-flex flex-1 rounded-lg border border-zinc-800 bg-zinc-950/80 p-0.5"
            >
                <Link
                    v-for="link in folderLinks"
                    :key="link.id"
                    :href="link.href"
                    class="flex-1 rounded-md px-2 py-1.5 text-center text-xs font-medium transition"
                    :class="
                        folder === link.id
                            ? 'bg-zinc-800 text-white'
                            : 'text-zinc-400 hover:text-zinc-200'
                    "
                >
                    {{ link.label }}
                </Link>
            </div>
            <button
                v-if="folder === 'trash'"
                type="button"
                class="md-btn-ghost !border-rose-500/30 !py-1.5 !text-xs !text-rose-300"
                data-testid="empty-trash-mobile"
                :disabled="!threads.length"
                @click="emptyTrash"
            >
                <Trash2 :size="14" />
            </button>
        </div>

        <div
            v-else-if="folder === 'trash'"
            class="mb-3 flex items-center justify-end px-1 lg:hidden"
        >
            <button
                type="button"
                class="md-btn-ghost !border-rose-500/30 !py-1.5 !text-xs !text-rose-300"
                data-testid="empty-trash-mobile"
                :disabled="!threads.length"
                @click="emptyTrash"
            >
                <Trash2 :size="14" />
                Empty trash
            </button>
        </div>

        <div
            class="md-card grid min-w-0 overflow-hidden lg:h-[calc(100vh-15rem)] lg:min-h-[540px] lg:grid-cols-[340px_1fr]"
            :class="
                mobileDetail && active
                    ? 'max-lg:fixed max-lg:inset-x-0 max-lg:bottom-[calc(4rem+env(safe-area-inset-bottom,0px))] max-lg:top-0 max-lg:z-30 max-lg:w-full max-lg:max-w-none max-lg:rounded-none max-lg:border-0'
                    : 'max-lg:-mx-4 max-lg:-mt-4 max-lg:min-h-[calc(100dvh-8.5rem-env(safe-area-inset-bottom,0px))] max-lg:w-[calc(100%+2rem)] max-lg:max-w-[100vw] max-lg:rounded-none max-lg:border-x-0 sm:max-lg:-mx-6 sm:max-lg:w-[calc(100%+3rem)]'
            "
            data-testid="inbox-card"
        >
            <div
                class="min-h-0 min-w-0 flex-col overflow-hidden border-b border-zinc-800 lg:flex lg:border-b-0 lg:border-r"
                :class="mobileDetail && active ? 'hidden lg:flex' : 'flex'"
                data-testid="inbox-thread-list"
            >
                <div class="relative border-b border-zinc-800 p-3">
                    <Search
                        :size="14"
                        class="pointer-events-none absolute left-6 top-1/2 -translate-y-1/2 text-zinc-500"
                    />
                    <input
                        v-model="search"
                        type="search"
                        :placeholder="`Search ${folderTitle.toLowerCase()}…`"
                        class="md-input pl-9"
                    />
                </div>
                <ul class="md-hide-scrollbar min-h-0 min-w-0 flex-1 overflow-x-hidden overflow-y-auto">
                    <li
                        v-for="thread in filtered"
                        :key="thread.id"
                        class="min-w-0 overflow-hidden"
                    >
                        <div
                            class="group flex min-w-0 items-start overflow-hidden border-b border-zinc-900 transition active:bg-white/[0.04] hover:bg-white/[0.03]"
                            :class="{
                                'bg-zinc-900/80': activeId === thread.id,
                            }"
                        >
                            <button
                                type="button"
                                class="flex min-w-0 flex-1 items-start gap-3 overflow-hidden px-4 py-3.5 text-left"
                                @click="selectThread(thread)"
                            >
                                <span
                                    class="relative mt-0.5 flex h-11 w-11 shrink-0 items-center justify-center rounded-full text-xs font-semibold lg:hidden"
                                    :class="avatarTone(thread)"
                                    aria-hidden="true"
                                    data-testid="inbox-thread-avatar"
                                >
                                    {{ avatarInitials(thread) }}
                                    <span
                                        v-if="thread.unread"
                                        class="absolute -right-0.5 -top-0.5 h-2.5 w-2.5 rounded-full border-2 border-zinc-950 bg-cyan-400"
                                    />
                                </span>
                                <span class="min-w-0 flex-1 overflow-hidden">
                                    <div
                                        class="flex min-w-0 items-center justify-between gap-2"
                                    >
                                        <span
                                            class="flex min-w-0 flex-1 items-center gap-2 overflow-hidden"
                                        >
                                            <span
                                                v-if="thread.unread"
                                                class="hidden h-1.5 w-1.5 shrink-0 rounded-full bg-cyan-400 lg:inline-flex"
                                            />
                                            <span
                                                class="block min-w-0 truncate text-sm"
                                                :class="
                                                    thread.unread
                                                        ? 'font-semibold text-white'
                                                        : 'text-zinc-300'
                                                "
                                                :title="threadFromEmail(thread)"
                                            >
                                                {{ threadFromLabel(thread) }}
                                            </span>
                                        </span>
                                        <span
                                            class="shrink-0 text-[11px] text-zinc-500"
                                        >
                                            {{ thread.updated }}
                                        </span>
                                    </div>
                                    <div
                                        class="mt-1 block min-w-0 truncate text-sm"
                                        :class="
                                            thread.unread
                                                ? 'font-medium text-zinc-100'
                                                : 'text-zinc-400'
                                        "
                                    >
                                        {{ thread.subject }}
                                    </div>
                                    <div
                                        v-if="thread.ai?.priority || thread.ai?.intent"
                                        class="mt-1 flex flex-wrap gap-1"
                                    >
                                        <StatusBadge
                                            v-if="thread.ai?.priority"
                                            :status="thread.ai.priority"
                                        />
                                        <StatusBadge
                                            v-if="thread.ai?.intent"
                                            :status="thread.ai.intent"
                                        />
                                    </div>
                                    <div
                                        class="mt-0.5 block min-w-0 truncate text-xs text-zinc-500"
                                    >
                                        {{ thread.snippet }}
                                    </div>
                                </span>
                            </button>
                            <div
                                class="shrink-0 pr-2 pt-2 opacity-100 transition lg:opacity-0 lg:group-hover:opacity-100"
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

            <div
                v-if="active"
                class="min-h-0 min-w-0 flex-col bg-black lg:bg-transparent"
                :class="mobileDetail ? 'flex' : 'hidden lg:flex'"
                data-testid="inbox-thread-detail"
            >
                <div
                    class="flex items-center gap-3 border-b border-zinc-800 px-4 pb-3.5 pt-[max(1rem,calc(env(safe-area-inset-top,0px)+0.5rem))] lg:items-start lg:gap-3 lg:px-6 lg:py-4"
                    data-testid="inbox-thread-toolbar"
                >
                    <button
                        type="button"
                        class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl border border-zinc-800 text-zinc-300 transition active:scale-95 lg:hidden"
                        aria-label="Back to conversations"
                        data-testid="inbox-mobile-back"
                        @click="closeMobileDetail"
                    >
                        <ArrowLeft :size="18" />
                    </button>
                    <div class="min-w-0 flex-1 overflow-hidden py-0.5">
                        <h2
                            class="line-clamp-2 break-words text-[17px] font-semibold leading-snug text-white lg:line-clamp-none lg:text-lg"
                        >
                            {{ active.subject }}
                        </h2>
                        <p class="mt-1.5 truncate text-sm text-zinc-400" :title="threadFromEmail(active)">
                            {{ threadFromLabel(active) }}
                            <span class="mx-1 text-zinc-600">·</span>
                            {{ active.updated }}
                        </p>
                        <div
                            v-if="active.ai?.priority || active.ai?.intent || active.ai?.language"
                            class="mt-2 flex flex-wrap gap-1"
                        >
                            <StatusBadge
                                v-if="active.ai?.priority"
                                :status="active.ai.priority"
                            />
                            <StatusBadge
                                v-if="active.ai?.intent"
                                :status="active.ai.intent"
                            />
                            <span
                                v-if="active.ai?.language"
                                class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium uppercase text-zinc-400 ring-1 ring-inset ring-zinc-500/20"
                            >
                                {{ active.ai.language }}
                            </span>
                        </div>
                    </div>
                    <div class="flex shrink-0 items-center gap-1.5 self-center lg:gap-2 lg:self-start">
                        <template v-if="folder !== 'trash'">
                            <button
                                type="button"
                                class="md-btn-ghost !px-2.5 !py-1.5 text-sm lg:!px-3"
                                :class="{ '!border-cyan-400/40 !text-cyan-300': replyOpen && replyMode === 'reply' }"
                                title="Reply (r)"
                                data-testid="reply-toggle"
                                @click="replyOpen && replyMode === 'reply' ? closeReply() : openReply()"
                            >
                                <Reply :size="15" />
                                <span class="hidden sm:inline">Reply</span>
                            </button>
                            <button
                                type="button"
                                class="md-btn-ghost !px-2.5 !py-1.5 text-sm lg:!px-3"
                                :class="{ '!border-cyan-400/40 !text-cyan-300': replyOpen && replyMode === 'forward' }"
                                title="Forward (f)"
                                data-testid="forward-toggle"
                                @click="replyOpen && replyMode === 'forward' ? closeReply() : openForward()"
                            >
                                <Forward :size="15" />
                                <span class="hidden sm:inline">Forward</span>
                            </button>
                        </template>
                        <RowActions
                            :items="headerActions"
                            @select="onHeaderAction"
                        />
                    </div>
                </div>
                <div
                    ref="messagesEl"
                    class="md-hide-scrollbar mx-auto min-h-0 min-w-0 w-full max-w-3xl flex-1 space-y-3 overflow-y-auto overflow-x-hidden px-4 py-3 lg:max-w-none lg:space-y-4 lg:p-6"
                    data-testid="thread-messages"
                >
                    <article
                        v-for="message in active.messages"
                        :key="message.id || message"
                        class="w-full min-w-0 overflow-hidden rounded-xl border bg-zinc-950 p-3 lg:p-4"
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
                            class="mb-3 flex items-center justify-between gap-3 text-xs text-zinc-500"
                            data-testid="message-header"
                        >
                            <span class="min-w-0 truncate">
                                {{
                                    (message.from_name || '').trim() ||
                                    message.from ||
                                    active.from
                                }}
                            </span>
                            <span class="shrink-0">{{ message.sent || '—' }}</span>
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
                        <div
                            v-if="message.fanout?.length"
                            class="-mt-1 mb-3 flex flex-wrap gap-1.5"
                            data-testid="group-fanout"
                        >
                            <span
                                v-for="g in message.fanout"
                                :key="g.id"
                                class="inline-flex items-center gap-1 rounded-full border border-cyan-400/30 bg-cyan-400/10 px-2 py-0.5 text-[11px] text-cyan-300"
                                :title="g.members.join(', ')"
                            >
                                <UsersRound :size="11" />
                                Sent to {{ g.email }} · copied to {{ g.members.length }}
                                {{ g.members.length === 1 ? 'member' : 'members' }}
                            </span>
                        </div>
                        <EmailFrame
                            v-if="message.html || message.text"
                            :html="message.html || ''"
                            :text="message.text || active.snippet || ''"
                            :title="`Message from ${message.from || active.from}`"
                        />
                        <p
                            v-else
                            class="text-sm leading-relaxed text-zinc-300"
                        >
                            {{ active.snippet }}
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
                    v-if="replyOpen"
                    class="shrink-0 border-t border-zinc-800 bg-zinc-950 p-4"
                    data-testid="reply-box"
                >
                    <div class="mb-2 flex items-center justify-between gap-3 text-xs">
                        <span v-if="replyMode === 'forward'" class="truncate text-zinc-500">
                            Forward
                            <span class="text-zinc-300">“{{ active.subject }}”</span>
                            with its attachments
                        </span>
                        <span v-else class="truncate text-zinc-500">
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
                            <button
                                type="button"
                                class="ml-1 rounded p-1 text-zinc-500 transition hover:bg-zinc-900 hover:text-zinc-200"
                                title="Close reply (your draft is kept)"
                                data-testid="reply-close"
                                @click="closeReply"
                            >
                                <X :size="14" />
                            </button>
                        </span>
                    </div>
                    <label v-if="replyMode === 'forward'" class="relative mb-2 block">
                        <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-xs text-zinc-500">To</span>
                        <input
                            v-model="forwardTo"
                            class="md-input !py-1.5 pl-10"
                            placeholder="name@example.com, staff@yourdomain.com"
                            data-testid="forward-to"
                        />
                    </label>
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
                            :placeholder="replyMode === 'forward' ? 'Add a note (optional)…' : 'Write a reply…'"
                            min-height="110px"
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
                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        <button
                            type="button"
                            class="md-btn-primary"
                            :disabled="sendingReply"
                            @click="sendReply"
                        >
                            <Send :size="16" />
                            {{ sendingReply ? 'Sending…' : replyMode === 'forward' ? 'Forward' : 'Send reply' }}
                        </button>
                        <button
                            v-if="replyDraftEnabled && replyMode === 'reply'"
                            type="button"
                            class="md-btn-ghost"
                            data-testid="suggest-reply"
                            title="Suggest a reply with AI"
                            :disabled="suggestingReply || sendingReply"
                            @click="suggestReply"
                        >
                            <Sparkles :size="16" :class="{ 'animate-pulse': suggestingReply }" />
                            {{ suggestingReply ? 'Drafting…' : 'Suggest reply' }}
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
                        <span
                            v-if="signatureEnabled"
                            class="ml-auto hidden text-xs text-zinc-500 sm:inline"
                        >
                            Your signature is added when it sends
                        </span>
                    </div>
                </div>
            </div>
            <div
                v-else
                class="hidden items-center justify-center p-12 text-sm text-zinc-500 lg:flex"
            >
                Select a conversation
            </div>
        </div>
    </AppLayout>
</template>
