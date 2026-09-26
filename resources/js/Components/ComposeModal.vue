<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import WysiwygEditor from '@/Components/WysiwygEditor.vue';
import {
    composeDraft,
    useComposeModal,
} from '@/composables/useComposeModal';
import { useTenant } from '@/composables/useTenant';
import { useToast } from '@/composables/useToast';
import { useAiFeatures } from '@/composables/useAiFeatures';
import {
    Calendar,
    Eye,
    File,
    Maximize2,
    Minimize2,
    Minus,
    FileImage,
    FileText,
    Paperclip,
    Plus,
    Send,
    Sparkles,
    Tag,
    X,
} from '@lucide/vue';

const { state, close, toggleMinimized, toggleExpanded } = useComposeModal();
const { canSend, activeWorkspace, activeProviderHealth, sendingFrom } =
    useTenant();
const { composeAssist } = useAiFeatures();
const page = usePage();
const toast = useToast();
const editorRef = ref(null);
const fileInput = ref(null);
const sending = ref(false);
const savingDraft = ref(false);
const assisting = ref(false);
const subjectSuggestions = ref([]);
const draftId = composeDraft.draftId;
const previewAttachment = ref(null);

const fromOptions = computed(() => {
    if (sendingFrom.value.length) {
        return sendingFrom.value;
    }
    const host = activeWorkspace.value?.host || 'workspace.local';
    const domain = host.includes('.')
        ? host.split('.').slice(-2).join('.')
        : host;
    return [`hello@${domain}`, `noreply@${domain}`];
});

const blankForm = () => ({
    from: fromOptions.value[0] || 'hello@example.com',
    to: '',
    cc: '',
    bcc: '',
    replyTo: '',
    subject: '',
    html: '<p>Hi there,</p><p>Write your message here…</p>',
    schedule: false,
    scheduleAt: '',
    tags: [],
});

const form = composeDraft.form;
if (!form.value) form.value = blankForm();
const tagInput = ref('');
/** @type {import('vue').Ref<Array<{id:number,name:string,size:number,type:string,url:string|null,isImage:boolean}>>} */
const attachments = composeDraft.attachments;

const snapshot = () =>
    JSON.stringify({ ...form.value, files: attachments.value.length });
const pristine = composeDraft.pristine;
const dirty = computed(() => state.open && snapshot() !== pristine.value);

const clearAttachments = () => {
    for (const a of attachments.value) {
        if (a.url) URL.revokeObjectURL(a.url);
    }
    attachments.value = [];
};

const reset = () => {
    form.value = {
        ...blankForm(),
        ...(state.defaults || {}),
    };
    if (
        state.defaults?.from &&
        !fromOptions.value.includes(state.defaults.from) &&
        fromOptions.value.length
    ) {
        // Keep explicit from override (e.g. domain page) even if not in list.
        form.value.from = state.defaults.from;
    } else if (!fromOptions.value.includes(form.value.from)) {
        form.value.from = fromOptions.value[0] || form.value.from;
    }
    tagInput.value = '';
    clearAttachments();
};

watch(
    () => state.session,
    () => {
        reset();
        pristine.value = snapshot();
    },
);

watch(
    () => state.open,
    (open) => {
        if (!open) {
            clearAttachments();
            document.body.style.overflow = '';
        }
    },
);

watch(
    () => state.open && state.expanded,
    (locked) => {
        document.body.style.overflow = locked ? 'hidden' : '';
    },
);

const discard = () => {
    if (dirty.value && !window.confirm('Discard this draft?')) return;
    close();
};

const title = computed(() => form.value.subject.trim() || 'New message');

watch(
    () => page.props.flash?.error,
    (message) => {
        if (message && state.open) {
            toast.error(message);
        }
    },
);

const onKey = (e) => {
    if (e.key !== 'Escape' || !state.open) return;
    // Escape shrinks the panel back down rather than throwing the draft away.
    if (state.expanded) toggleExpanded();
    else if (!state.minimized) toggleMinimized();
};

onMounted(() => window.addEventListener('keydown', onKey));
onUnmounted(() => {
    window.removeEventListener('keydown', onKey);
    // Keep the draft (and its attachment previews) while the panel stays open
    // across page visits; only tidy up once it is closed.
    if (!state.open) clearAttachments();
    document.body.style.overflow = '';
});

const addTag = () => {
    const t = tagInput.value.trim().toLowerCase();
    if (!t || form.value.tags.includes(t)) return;
    form.value.tags.push(t);
    tagInput.value = '';
};

const removeTag = (t) => {
    form.value.tags = form.value.tags.filter((x) => x !== t);
};

const formatSize = (bytes) => {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
};

const onAttach = (e) => {
    const files = Array.from(e.target.files || []);
    e.target.value = '';
    if (!files.length) return;

    for (const file of files) {
        const isImage = file.type.startsWith('image/') && file.type !== 'image/svg+xml';
        const isPdf = file.type === 'application/pdf';
        const url = URL.createObjectURL(file);
        attachments.value.push({
            id: Date.now() + Math.random(),
            name: file.name,
            size: file.size,
            type: file.type || 'application/octet-stream',
            url,
            isImage,
            previewable: isImage || isPdf,
            previewKind: isImage ? 'image' : isPdf ? 'pdf' : null,
            file,
        });
    }
    toast.success(
        files.length === 1
            ? 'Attachment added.'
            : `${files.length} attachments added.`,
    );
};

const removeAttachment = (id) => {
    const item = attachments.value.find((a) => a.id === id);
    if (item?.url) URL.revokeObjectURL(item.url);
    attachments.value = attachments.value.filter((a) => a.id !== id);
};

const insertIntoBody = (att) => {
    if (!att.isImage || !att.url) {
        toast.info('Only images can be inserted into the body.');
        return;
    }
    editorRef.value?.insertImage(att.url, att.name);
    toast.success('Image inserted into body.');
};

const openAttachmentPreview = (att) => {
    if (!att.previewable || !att.url) {
        return;
    }
    previewAttachment.value = att;
};

const closeAttachmentPreview = () => {
    previewAttachment.value = null;
};

const runComposeAssist = async (action) => {
    if (!composeAssist.value || assisting.value) {
        return;
    }

    assisting.value = true;
    subjectSuggestions.value = [];

    try {
        const { data } = await window.axios.post(route('ai.compose'), {
            action,
            html: form.value.html,
            subject: form.value.subject,
            tone: action === 'tone' ? 'friendly' : undefined,
            language: action === 'translate' ? 'en' : undefined,
        });

        if (action === 'subject') {
            subjectSuggestions.value = data.subjects || [];
            if (data.subject) {
                form.value.subject = data.subject;
            }
            toast.success('Subject suggestions ready.');
        } else if (data.html) {
            form.value.html = data.html;
            toast.success('Draft updated.');
        }
    } catch (error) {
        toast.error(
            error?.response?.data?.message ||
                error?.response?.data?.error ||
                'Compose assist failed.',
        );
    } finally {
        assisting.value = false;
    }
};

const fileIcon = (att) => {
    if (att.isImage) return FileImage;
    if (att.type.includes('pdf') || att.type.includes('text')) return FileText;
    return File;
};

const saveDraft = () => {
    savingDraft.value = true;
    const data = {
        from: form.value.from,
        to: form.value.to.trim() || null,
        cc: form.value.cc.trim() || null,
        bcc: (form.value.bcc || '').trim() || null,
        subject: form.value.subject.trim() || null,
        html: form.value.html,
        thread_id: state.defaults?.thread_id ?? null,
    };

    const opts = {
        preserveScroll: true,
        preserveState: true,
        onFinish: () => {
            savingDraft.value = false;
        },
        onSuccess: (response) => {
            const id = response.props.flash?.draft_id;
            if (id) {
                draftId.value = id;
            }
            pristine.value = snapshot();
            toast.success('Draft saved.');
        },
        onError: () => toast.error('Could not save draft.'),
    };

    if (draftId.value) {
        router.put(route('drafts.update', draftId.value), data, opts);
    } else {
        router.post(route('drafts.store'), data, opts);
    }
};

const submit = () => {
    if (!canSend.value) {
        toast.error(
            'Cannot send — this workspace has no active mail provider.',
        );
        return;
    }
    if (!form.value.to.trim() || !form.value.subject.trim()) {
        toast.error('To and subject are required.');
        return;
    }
    if (form.value.schedule && !form.value.scheduleAt) {
        toast.error('Choose a date and time to schedule this send.');
        return;
    }

    sending.value = true;
    const via = activeProviderHealth.value.provider?.name || 'provider';

    const data = {
        from: form.value.from,
        to: form.value.to.trim(),
        cc: form.value.cc.trim() || null,
        bcc: (form.value.bcc || '').trim() || null,
        reply_to: form.value.replyTo.trim() || null,
        subject: form.value.subject.trim(),
        html: form.value.html,
        text: form.value.html
            .replace(/<[^>]+>/g, ' ')
            .replace(/\s+/g, ' ')
            .trim(),
        tags: form.value.tags,
        schedule: form.value.schedule,
        schedule_at: form.value.schedule ? form.value.scheduleAt : null,
    };

    if (draftId.value) {
        data.draft_id = draftId.value;
    }

    const fileList = attachments.value.map((a) => a.file).filter(Boolean);
    if (fileList.length) {
        data.attachments = fileList;
    }

    data.stay = 1;

    router.post(route('emails.store'), data, {
        forceFormData: true,
        preserveScroll: true,
        preserveState: true,
        onFinish: () => {
            sending.value = false;
        },
        onSuccess: (response) => {
            if (response.props.flash?.error) {
                // Provider rejected it: keep the draft open so it can be fixed.
                state.minimized = false;
                return;
            }
            toast.success(
                form.value.schedule
                    ? `Email scheduled via ${via}.`
                    : `Email sent via ${via}.`,
            );
            close();
        },
        onError: (errors) => {
            const first =
                errors.from ||
                errors.to ||
                errors.subject ||
                errors.schedule_at ||
                errors.html ||
                Object.values(errors)[0];
            toast.error(
                typeof first === 'string' ? first : 'Could not send email.',
            );
        },
    });
};
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition duration-150 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="state.open"
                class="fixed inset-0 z-[90] flex"
                :class="
                    state.expanded
                        ? 'items-end justify-center bg-black/60 p-3 backdrop-blur-md sm:items-center sm:p-6'
                        : 'pointer-events-none items-end justify-end sm:px-6'
                "
                @click.self="state.expanded && toggleExpanded()"
            >
                <Transition
                    enter-active-class="transition duration-200 ease-out"
                    enter-from-class="translate-y-4 opacity-0 sm:scale-95"
                    enter-to-class="translate-y-0 opacity-100 sm:scale-100"
                    leave-active-class="transition duration-150 ease-in"
                    leave-from-class="translate-y-0 opacity-100 sm:scale-100"
                    leave-to-class="translate-y-4 opacity-0 sm:scale-95"
                    appear
                >
                    <div
                        v-if="state.open"
                        class="pointer-events-auto flex w-full flex-col overflow-hidden border border-zinc-800 bg-zinc-950 shadow-2xl shadow-black/60"
                        :class="
                            state.expanded
                                ? 'max-h-[92vh] max-w-3xl rounded-2xl'
                                : state.minimized
                                  ? 'rounded-t-xl sm:w-80'
                                  : 'max-h-[88vh] rounded-t-2xl sm:max-h-[80vh] sm:w-[560px]'
                        "
                        role="dialog"
                        :aria-modal="state.expanded ? 'true' : 'false'"
                        aria-labelledby="compose-title"
                        data-testid="compose-panel"
                    >
                        <div
                            class="flex shrink-0 cursor-pointer select-none items-center justify-between gap-3 border-b border-zinc-800 bg-zinc-900/80 py-2.5 pl-4 pr-2"
                            @click="!state.expanded && toggleMinimized()"
                        >
                            <div class="min-w-0">
                                <h2
                                    id="compose-title"
                                    class="truncate text-sm font-semibold text-white"
                                >
                                    {{ title }}
                                </h2>
                                <p
                                    v-if="!state.minimized"
                                    class="truncate text-xs text-zinc-500"
                                >
                                    Via
                                    {{
                                        activeProviderHealth.provider?.name ||
                                        'no provider'
                                    }}
                                    · {{ activeWorkspace?.host || 'workspace' }}
                                </p>
                            </div>
                            <div class="flex shrink-0 items-center" @click.stop>
                                <button
                                    type="button"
                                    class="rounded-lg p-1.5 text-zinc-500 transition hover:bg-zinc-800 hover:text-white"
                                    :title="state.minimized ? 'Restore' : 'Minimize'"
                                    @click="toggleMinimized"
                                >
                                    <Minus :size="16" />
                                </button>
                                <button
                                    type="button"
                                    class="hidden rounded-lg p-1.5 text-zinc-500 transition hover:bg-zinc-800 hover:text-white sm:inline-flex"
                                    :title="state.expanded ? 'Exit full screen' : 'Full screen'"
                                    @click="toggleExpanded"
                                >
                                    <Minimize2 v-if="state.expanded" :size="15" />
                                    <Maximize2 v-else :size="15" />
                                </button>
                                <button
                                    type="button"
                                    class="rounded-lg p-1.5 text-zinc-500 transition hover:bg-zinc-800 hover:text-white"
                                    title="Discard draft"
                                    @click="discard"
                                >
                                    <X :size="16" />
                                </button>
                            </div>
                        </div>

                        <form
                            v-show="!state.minimized"
                            class="flex min-h-0 flex-1 flex-col"
                            @submit.prevent="submit"
                        >
                            <div class="space-y-4 overflow-y-auto px-5 py-4">
                                <div class="grid gap-3 sm:grid-cols-2">
                                    <div>
                                        <label
                                            class="mb-1.5 block text-xs text-zinc-500"
                                            >From</label
                                        >
                                        <select
                                            v-model="form.from"
                                            class="md-input"
                                        >
                                            <option
                                                v-for="addr in fromOptions"
                                                :key="addr"
                                                :value="addr"
                                            >
                                                {{ addr }}
                                            </option>
                                        </select>
                                    </div>
                                    <div>
                                        <label
                                            class="mb-1.5 block text-xs text-zinc-500"
                                            >To</label
                                        >
                                        <input
                                            v-model="form.to"
                                            class="md-input"
                                            placeholder="user@example.com"
                                            required
                                        />
                                    </div>
                                </div>
                                <div class="grid gap-3 sm:grid-cols-3">
                                    <div>
                                        <label
                                            class="mb-1.5 block text-xs text-zinc-500"
                                            >Cc</label
                                        >
                                        <input
                                            v-model="form.cc"
                                            class="md-input"
                                            placeholder="optional"
                                        />
                                    </div>
                                    <div>
                                        <label
                                            class="mb-1.5 block text-xs text-zinc-500"
                                            >Bcc</label
                                        >
                                        <input
                                            v-model="form.bcc"
                                            class="md-input"
                                            placeholder="optional"
                                            data-testid="compose-bcc"
                                        />
                                    </div>
                                    <div>
                                        <label
                                            class="mb-1.5 block text-xs text-zinc-500"
                                            >Reply-to</label
                                        >
                                        <input
                                            v-model="form.replyTo"
                                            class="md-input"
                                            placeholder="support@acme.com"
                                        />
                                    </div>
                                </div>
                                <div>
                                    <div class="mb-1.5 flex flex-wrap items-center justify-between gap-2">
                                        <label class="block text-xs text-zinc-500"
                                            >Subject</label
                                        >
                                        <button
                                            v-if="composeAssist"
                                            type="button"
                                            class="inline-flex items-center gap-1 text-xs text-cyan-300 hover:text-cyan-200 disabled:opacity-50"
                                            data-testid="compose-ai-subject"
                                            :disabled="assisting"
                                            @click="runComposeAssist('subject')"
                                        >
                                            <Sparkles :size="12" />
                                            Suggest subject
                                        </button>
                                    </div>
                                    <input
                                        v-model="form.subject"
                                        class="md-input"
                                        required
                                    />
                                    <div
                                        v-if="subjectSuggestions.length"
                                        class="mt-2 flex flex-wrap gap-1.5"
                                    >
                                        <button
                                            v-for="suggestion in subjectSuggestions"
                                            :key="suggestion"
                                            type="button"
                                            class="rounded-full border border-zinc-700 px-2.5 py-1 text-[11px] text-zinc-300 hover:border-cyan-400/40 hover:text-cyan-200"
                                            @click="form.subject = suggestion"
                                        >
                                            {{ suggestion }}
                                        </button>
                                    </div>
                                </div>
                                <div>
                                    <div class="mb-1.5 flex flex-wrap items-center justify-between gap-2">
                                        <label class="block text-xs text-zinc-500"
                                            >Body</label
                                        >
                                        <div
                                            v-if="composeAssist"
                                            class="flex flex-wrap gap-2"
                                        >
                                            <button
                                                type="button"
                                                class="inline-flex items-center gap-1 text-xs text-cyan-300 hover:text-cyan-200 disabled:opacity-50"
                                                data-testid="compose-ai-rewrite"
                                                :disabled="assisting"
                                                @click="runComposeAssist('rewrite')"
                                            >
                                                <Sparkles :size="12" />
                                                {{ assisting ? 'Working…' : 'Rewrite' }}
                                            </button>
                                            <button
                                                type="button"
                                                class="text-xs text-zinc-400 hover:text-cyan-200 disabled:opacity-50"
                                                :disabled="assisting"
                                                @click="runComposeAssist('shorten')"
                                            >
                                                Shorten
                                            </button>
                                            <button
                                                type="button"
                                                class="text-xs text-zinc-400 hover:text-cyan-200 disabled:opacity-50"
                                                :disabled="assisting"
                                                @click="runComposeAssist('tone')"
                                            >
                                                Friendlier tone
                                            </button>
                                        </div>
                                    </div>
                                    <WysiwygEditor
                                        ref="editorRef"
                                        v-model="form.html"
                                        variant="email"
                                        min-height="220px"
                                    />
                                </div>

                                <!-- Attachments -->
                                <div>
                                    <div
                                        class="mb-2 flex items-center justify-between"
                                    >
                                        <label
                                            class="flex items-center gap-1.5 text-xs text-zinc-500"
                                        >
                                            <Paperclip :size="12" />
                                            Attachments
                                            <span
                                                v-if="attachments.length"
                                                class="text-zinc-600"
                                                >({{ attachments.length }})</span
                                            >
                                        </label>
                                        <button
                                            type="button"
                                            class="inline-flex items-center gap-1 text-xs text-cyan-300 hover:text-cyan-200"
                                            @click="fileInput?.click()"
                                        >
                                            <Plus :size="12" />
                                            Add files
                                        </button>
                                    </div>

                                    <div
                                        v-if="!attachments.length"
                                        class="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-zinc-700 bg-black/20 px-4 py-6 text-center transition hover:border-cyan-400/40 hover:bg-cyan-400/5"
                                        @click="fileInput?.click()"
                                        @dragover.prevent
                                        @drop.prevent="
                                            onAttach({
                                                target: { files: $event.dataTransfer.files, value: '' },
                                            })
                                        "
                                    >
                                        <Paperclip
                                            :size="20"
                                            class="text-zinc-500"
                                        />
                                        <p class="text-sm text-zinc-400">
                                            Drop files here or click to attach
                                        </p>
                                        <p class="text-[11px] text-zinc-600">
                                            Images can be inserted into the body
                                        </p>
                                    </div>

                                    <ul v-else class="grid gap-2 sm:grid-cols-2">
                                        <li
                                            v-for="att in attachments"
                                            :key="att.id"
                                            class="group relative overflow-hidden rounded-xl border border-zinc-800 bg-zinc-900/60"
                                        >
                                            <button
                                                v-if="att.isImage"
                                                type="button"
                                                class="relative aspect-[16/10] w-full bg-zinc-950 text-left"
                                                title="Preview"
                                                @click="openAttachmentPreview(att)"
                                            >
                                                <img
                                                    :src="att.url"
                                                    :alt="att.name"
                                                    class="h-full w-full object-cover"
                                                />
                                                <div
                                                    class="absolute inset-0 flex items-end justify-between gap-2 bg-gradient-to-t from-black/70 to-transparent p-2 opacity-0 transition group-hover:opacity-100"
                                                >
                                                    <span
                                                        class="inline-flex items-center gap-1 rounded-full bg-white/90 px-2.5 py-1 text-[11px] font-medium text-zinc-900"
                                                    >
                                                        <Eye :size="12" />
                                                        Preview
                                                    </span>
                                                    <span
                                                        class="rounded-full bg-white px-2.5 py-1 text-[11px] font-medium text-zinc-900"
                                                        @click.stop="
                                                            insertIntoBody(att)
                                                        "
                                                    >
                                                        Insert into body
                                                    </span>
                                                </div>
                                            </button>
                                            <div
                                                class="flex items-start gap-2.5 p-3"
                                            >
                                                <span
                                                    v-if="!att.isImage"
                                                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-zinc-800 text-zinc-400"
                                                >
                                                    <component
                                                        :is="fileIcon(att)"
                                                        :size="16"
                                                    />
                                                </span>
                                                <div class="min-w-0 flex-1">
                                                    <div
                                                        class="truncate text-sm text-zinc-200"
                                                    >
                                                        {{ att.name }}
                                                    </div>
                                                    <div
                                                        class="mt-0.5 text-[11px] text-zinc-500"
                                                    >
                                                        {{
                                                            formatSize(att.size)
                                                        }}
                                                        <button
                                                            v-if="att.previewable"
                                                            type="button"
                                                            class="ml-2 text-cyan-300 hover:text-cyan-200"
                                                            @click="
                                                                openAttachmentPreview(
                                                                    att,
                                                                )
                                                            "
                                                        >
                                                            Preview
                                                        </button>
                                                        <button
                                                            v-if="att.isImage"
                                                            type="button"
                                                            class="ml-2 text-cyan-300 hover:text-cyan-200"
                                                            @click="
                                                                insertIntoBody(
                                                                    att,
                                                                )
                                                            "
                                                        >
                                                            Insert
                                                        </button>
                                                    </div>
                                                </div>
                                                <button
                                                    type="button"
                                                    class="rounded-md p-1 text-zinc-500 hover:bg-zinc-800 hover:text-rose-400"
                                                    title="Remove"
                                                    @click="
                                                        removeAttachment(att.id)
                                                    "
                                                >
                                                    <X :size="14" />
                                                </button>
                                            </div>
                                        </li>
                                    </ul>

                                    <input
                                        ref="fileInput"
                                        type="file"
                                        class="hidden"
                                        multiple
                                        @change="onAttach"
                                    />
                                </div>

                                <div>
                                    <label
                                        class="mb-1.5 flex items-center gap-1.5 text-xs text-zinc-500"
                                    >
                                        <Tag :size="12" />
                                        Tags
                                    </label>
                                    <div
                                        class="flex flex-wrap items-center gap-2"
                                    >
                                        <span
                                            v-for="t in form.tags"
                                            :key="t"
                                            class="inline-flex items-center gap-1 rounded-full bg-cyan-400/10 px-2.5 py-1 text-xs text-cyan-300"
                                        >
                                            {{ t }}
                                            <button
                                                type="button"
                                                class="text-cyan-400/70 hover:text-white"
                                                @click="removeTag(t)"
                                            >
                                                <X :size="12" />
                                            </button>
                                        </span>
                                        <input
                                            v-model="tagInput"
                                            class="md-input max-w-[160px] py-1.5 text-xs"
                                            placeholder="Add tag…"
                                            @keydown.enter.prevent="addTag"
                                        />
                                    </div>
                                </div>

                                <div
                                    class="flex flex-col gap-3 rounded-xl border border-zinc-800 bg-black/30 p-3 sm:flex-row sm:items-center sm:justify-between"
                                >
                                    <label
                                        class="flex items-center gap-2 text-sm text-zinc-300"
                                    >
                                        <input
                                            v-model="form.schedule"
                                            type="checkbox"
                                            class="rounded border-zinc-700 bg-zinc-900 text-cyan-400 focus:ring-cyan-400/40"
                                        />
                                        <Calendar
                                            :size="14"
                                            class="text-cyan-300"
                                        />
                                        Schedule send
                                    </label>
                                    <input
                                        v-if="form.schedule"
                                        v-model="form.scheduleAt"
                                        type="datetime-local"
                                        class="md-input max-w-xs"
                                    />
                                </div>
                            </div>

                            <div
                                class="flex shrink-0 items-center justify-between gap-3 border-t border-zinc-800 px-5 py-3"
                            >
                                <button
                                    type="button"
                                    class="md-btn-ghost"
                                    @click="fileInput?.click()"
                                >
                                    <Paperclip :size="16" />
                                    Attach
                                </button>
                                <div class="flex items-center gap-2">
                                    <button
                                        type="button"
                                        class="md-btn-ghost"
                                        :disabled="savingDraft"
                                        @click="saveDraft"
                                    >
                                        {{ savingDraft ? 'Saving…' : 'Save draft' }}
                                    </button>
                                    <button
                                        type="button"
                                        class="md-btn-ghost"
                                        @click="discard"
                                    >
                                        Discard
                                    </button>
                                    <button
                                        type="submit"
                                        class="md-btn-primary"
                                        :disabled="sending || !canSend"
                                    >
                                        <Send :size="16" />
                                        {{
                                            sending
                                                ? 'Sending…'
                                                : form.schedule
                                                  ? 'Schedule'
                                                  : 'Send email'
                                        }}
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </Transition>
            </div>
        </Transition>
    </Teleport>

    <Teleport to="body">
        <Transition
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition duration-150 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="previewAttachment"
                class="fixed inset-0 z-[100] flex items-center justify-center bg-black/80 p-3 backdrop-blur-md sm:p-6"
                data-testid="compose-attachment-preview"
                @click.self="closeAttachmentPreview"
            >
                <div
                    class="flex max-h-[92vh] w-full max-w-5xl flex-col overflow-hidden rounded-2xl border border-zinc-800 bg-zinc-950 shadow-2xl"
                    role="dialog"
                    aria-modal="true"
                >
                    <div
                        class="flex shrink-0 items-center justify-between gap-3 border-b border-zinc-800 px-4 py-3"
                    >
                        <div class="min-w-0 truncate text-sm font-medium text-white">
                            {{ previewAttachment.name }}
                        </div>
                        <button
                            type="button"
                            class="rounded-lg p-1.5 text-zinc-500 hover:bg-zinc-900 hover:text-white"
                            @click="closeAttachmentPreview"
                        >
                            <X :size="16" />
                        </button>
                    </div>
                    <div
                        class="flex min-h-0 flex-1 items-center justify-center overflow-auto bg-zinc-900/40 p-3 sm:p-5"
                    >
                        <img
                            v-if="previewAttachment.previewKind === 'image'"
                            :src="previewAttachment.url"
                            :alt="previewAttachment.name"
                            class="max-h-[75vh] max-w-full rounded-lg object-contain"
                        />
                        <iframe
                            v-else-if="previewAttachment.previewKind === 'pdf'"
                            :src="previewAttachment.url"
                            :title="previewAttachment.name"
                            class="h-[75vh] w-full rounded-lg border border-zinc-800 bg-white"
                        />
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
