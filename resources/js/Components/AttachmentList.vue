<script setup>
import { computed } from 'vue';
import {
    Download,
    File,
    FileArchive,
    FileAudio,
    FileCode,
    FileImage,
    FileSpreadsheet,
    FileText,
    FileVideo,
} from '@lucide/vue';

const props = defineProps({
    attachments: { type: Array, default: () => [] },
    compact: { type: Boolean, default: false },
});

const images = computed(() => props.attachments.filter((a) => a.is_image));
const files = computed(() =>
    props.compact ? props.attachments : props.attachments.filter((a) => !a.is_image),
);

const iconFor = (a) => {
    const type = (a.content_type || '').toLowerCase();
    const ext = (a.extension || '').toLowerCase();
    if (type.startsWith('image/')) return FileImage;
    if (type.startsWith('video/')) return FileVideo;
    if (type.startsWith('audio/')) return FileAudio;
    if (['zip', 'rar', '7z', 'gz', 'tar'].includes(ext) || type.includes('zip')) return FileArchive;
    if (['csv', 'xls', 'xlsx', 'numbers', 'ods'].includes(ext) || type.includes('spreadsheet') || type.includes('csv')) return FileSpreadsheet;
    if (['json', 'xml', 'html', 'js', 'ts', 'php', 'py'].includes(ext)) return FileCode;
    if (type.includes('pdf') || type.startsWith('text/') || ['doc', 'docx', 'rtf', 'txt', 'md', 'pages', 'odt'].includes(ext)) return FileText;
    return File;
};

const tint = (a) => {
    const ext = (a.extension || '').toLowerCase();
    if ((a.content_type || '').includes('pdf')) return 'bg-rose-500/15 text-rose-300';
    if (['xls', 'xlsx', 'csv'].includes(ext)) return 'bg-emerald-500/15 text-emerald-300';
    if (['doc', 'docx'].includes(ext)) return 'bg-sky-500/15 text-sky-300';
    if (a.is_image) return 'bg-violet-500/15 text-violet-300';
    return 'bg-zinc-800 text-zinc-300';
};

const label = (a) => (a.extension ? a.extension.toUpperCase() : (a.content_type || 'file').split('/').pop());
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
            <a
                v-for="a in images"
                :key="a.id"
                :href="a.url"
                class="group relative block h-28 w-40 overflow-hidden rounded-lg border border-zinc-800 bg-zinc-900"
                :title="`Download ${a.filename} (${a.size_label})`"
                download
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
                    <Download :size="12" class="shrink-0 opacity-70 group-hover:opacity-100" />
                </span>
            </a>
        </div>

        <div v-if="files.length" class="flex flex-wrap gap-2">
            <a
                v-for="a in files"
                :key="a.id"
                :href="a.url"
                class="group inline-flex max-w-full items-center gap-2.5 rounded-lg border border-zinc-800 bg-zinc-950 py-1.5 pl-1.5 pr-3 text-left transition hover:border-zinc-700 hover:bg-zinc-900"
                :title="`Download ${a.filename}`"
                download
            >
                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md" :class="tint(a)">
                    <component :is="iconFor(a)" :size="16" />
                </span>
                <span class="min-w-0">
                    <span class="block max-w-[14rem] truncate text-xs font-medium text-zinc-200">
                        {{ a.filename }}
                    </span>
                    <span class="block text-[11px] text-zinc-500">
                        {{ label(a) }} · {{ a.size_label }}
                    </span>
                </span>
                <Download :size="14" class="shrink-0 text-zinc-500 transition group-hover:text-cyan-300" />
            </a>
        </div>
    </div>
</template>
