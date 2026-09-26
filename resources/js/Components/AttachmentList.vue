<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import {
    Download,
    Eye,
    File,
    FileArchive,
    FileAudio,
    FileCode,
    FileImage,
    FileSpreadsheet,
    FileText,
    FileVideo,
    X,
} from '@lucide/vue';

const props = defineProps({
    attachments: { type: Array, default: () => [] },
    compact: { type: Boolean, default: false },
});

const preview = ref(null);

const images = computed(() => props.attachments.filter((a) => a.is_image));
const files = computed(() =>
    props.compact
        ? props.attachments
        : props.attachments.filter((a) => !a.is_image),
);

const canPreview = (a) =>
    Boolean(a.previewable && a.preview_url) || Boolean(a.is_image && a.preview_url);

const openPreview = (a) => {
    if (!canPreview(a)) {
        return;
    }
    preview.value = a;
};

const closePreview = () => {
    preview.value = null;
};

const iconFor = (a) => {
    const type = (a.content_type || '').toLowerCase();
    const ext = (a.extension || '').toLowerCase();
    if (type.startsWith('image/')) return FileImage;
    if (type.startsWith('video/')) return FileVideo;
    if (type.startsWith('audio/')) return FileAudio;
    if (
        ['zip', 'rar', '7z', 'gz', 'tar'].includes(ext) ||
        type.includes('zip')
    )
        return FileArchive;
    if (
        ['csv', 'xls', 'xlsx', 'numbers', 'ods'].includes(ext) ||
        type.includes('spreadsheet') ||
        type.includes('csv')
    )
        return FileSpreadsheet;
    if (['json', 'xml', 'html', 'js', 'ts', 'php', 'py'].includes(ext))
        return FileCode;
    if (
        type.includes('pdf') ||
        type.startsWith('text/') ||
        ['doc', 'docx', 'rtf', 'txt', 'md', 'pages', 'odt'].includes(ext)
    )
        return FileText;
    return File;
};

const tint = (a) => {
    const ext = (a.extension || '').toLowerCase();
    if ((a.content_type || '').includes('pdf'))
        return 'bg-rose-500/15 text-rose-300';
    if (['xls', 'xlsx', 'csv'].includes(ext))
        return 'bg-emerald-500/15 text-emerald-300';
    if (['doc', 'docx'].includes(ext)) return 'bg-sky-500/15 text-sky-300';
    if (a.is_image) return 'bg-violet-500/15 text-violet-300';
    return 'bg-zinc-800 text-zinc-300';
};

const label = (a) =>
    a.extension
        ? a.extension.toUpperCase()
        : (a.content_type || 'file').split('/').pop();

const onKey = (e) => {
    if (e.key === 'Escape' && preview.value) {
        closePreview();
    }
};

onMounted(() => window.addEventListener('keydown', onKey));
onUnmounted(() => window.removeEventListener('keydown', onKey));

watch(preview, (open) => {
    document.body.style.overflow = open ? 'hidden' : '';
});
</script>

<template>
    <div v-if="attachments.length" class="space-y-2" data-testid="attachments">
        <div
            v-if="!compact"
            class="flex items-center gap-1.5 text-[11px] uppercase tracking-wide text-zinc-500"
        >
            {{ attachments.length }}
            {{ attachments.length === 1 ? 'attachment' : 'attachments' }}
        </div>

        <div v-if="!compact && images.length" class="flex flex-wrap gap-2">
            <button
                v-for="a in images"
                :key="a.id"
                type="button"
                class="group relative block h-28 w-40 overflow-hidden rounded-lg border border-zinc-800 bg-zinc-900 text-left"
                :title="`Preview ${a.filename}`"
                @click="openPreview(a)"
            >
                <img
                    :src="a.preview_url"
                    :alt="a.filename"
                    loading="lazy"
                    class="h-full w-full object-cover transition group-hover:scale-[1.03]"
                />
                <span
                    class="absolute inset-x-0 bottom-0 flex items-center justify-between gap-2 bg-gradient-to-t from-black/80 to-transparent px-2 pb-1.5 pt-5 text-[11px] text-white"
                >
                    <span class="truncate">{{ a.filename }}</span>
                    <Eye
                        :size="12"
                        class="shrink-0 opacity-70 group-hover:opacity-100"
                    />
                </span>
            </button>
        </div>

        <div v-if="files.length" class="flex flex-wrap gap-2">
            <div
                v-for="a in files"
                :key="a.id"
                class="group inline-flex max-w-full items-center gap-2 rounded-lg border border-zinc-800 bg-zinc-950 py-1.5 pl-1.5 pr-2 text-left"
            >
                <button
                    type="button"
                    class="inline-flex min-w-0 flex-1 items-center gap-2.5 rounded-md pr-1 text-left transition hover:bg-zinc-900"
                    :disabled="!canPreview(a)"
                    :title="
                        canPreview(a)
                            ? `Preview ${a.filename}`
                            : a.filename
                    "
                    @click="canPreview(a) && openPreview(a)"
                >
                    <span
                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md"
                        :class="tint(a)"
                    >
                        <component :is="iconFor(a)" :size="16" />
                    </span>
                    <span class="min-w-0">
                        <span
                            class="block max-w-[14rem] truncate text-xs font-medium text-zinc-200"
                        >
                            {{ a.filename }}
                        </span>
                        <span class="block text-[11px] text-zinc-500">
                            {{ label(a) }} · {{ a.size_label }}
                        </span>
                    </span>
                </button>
                <button
                    v-if="canPreview(a)"
                    type="button"
                    class="rounded-md p-1.5 text-zinc-500 transition hover:bg-zinc-800 hover:text-cyan-300"
                    :title="`Preview ${a.filename}`"
                    @click="openPreview(a)"
                >
                    <Eye :size="14" />
                </button>
                <a
                    :href="a.url"
                    class="rounded-md p-1.5 text-zinc-500 transition hover:bg-zinc-800 hover:text-cyan-300"
                    :title="`Download ${a.filename}`"
                    download
                >
                    <Download :size="14" />
                </a>
            </div>
        </div>

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
                    v-if="preview"
                    class="fixed inset-0 z-[95] flex items-center justify-center bg-black/80 p-3 backdrop-blur-md sm:p-6"
                    data-testid="attachment-preview"
                    @click.self="closePreview"
                >
                    <div
                        class="flex max-h-[92vh] w-full max-w-5xl flex-col overflow-hidden rounded-2xl border border-zinc-800 bg-zinc-950 shadow-2xl"
                        role="dialog"
                        aria-modal="true"
                        :aria-label="`Preview ${preview.filename}`"
                    >
                        <div
                            class="flex shrink-0 items-center justify-between gap-3 border-b border-zinc-800 px-4 py-3"
                        >
                            <div class="min-w-0">
                                <div
                                    class="truncate text-sm font-medium text-white"
                                >
                                    {{ preview.filename }}
                                </div>
                                <div class="text-xs text-zinc-500">
                                    {{ label(preview) }} ·
                                    {{ preview.size_label }}
                                </div>
                            </div>
                            <div class="flex shrink-0 items-center gap-1">
                                <a
                                    :href="preview.url"
                                    class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs text-zinc-400 transition hover:bg-zinc-900 hover:text-white"
                                    download
                                >
                                    <Download :size="14" />
                                    Download
                                </a>
                                <button
                                    type="button"
                                    class="rounded-lg p-1.5 text-zinc-500 hover:bg-zinc-900 hover:text-white"
                                    @click="closePreview"
                                >
                                    <X :size="16" />
                                </button>
                            </div>
                        </div>

                        <div
                            class="flex min-h-0 flex-1 items-center justify-center overflow-auto bg-zinc-900/40 p-3 sm:p-5"
                        >
                            <img
                                v-if="preview.preview_kind === 'image'"
                                :src="preview.preview_url"
                                :alt="preview.filename"
                                class="max-h-[75vh] max-w-full rounded-lg object-contain shadow-lg"
                            />
                            <iframe
                                v-else-if="preview.preview_kind === 'pdf'"
                                :src="preview.preview_url"
                                :title="preview.filename"
                                class="h-[75vh] w-full rounded-lg border border-zinc-800 bg-white"
                            />
                            <div
                                v-else
                                class="text-sm text-zinc-400"
                            >
                                Preview is not available for this file type.
                            </div>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>
    </div>
</template>
