<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import {
    SANDBOX,
    buildSrcdoc,
    contentHeight,
    hardenLinks,
    splitQuotedHtml,
    splitQuotedText,
} from '@/lib/emailFrame';

const props = defineProps({
    html: { type: String, default: '' },
    text: { type: String, default: '' },
    minHeight: { type: Number, default: 80 },
    title: { type: String, default: 'Email content' },
    /** When false, always show the full body (e.g. raw preview). */
    collapseQuotes: { type: Boolean, default: true },
});

const frame = ref(null);
const height = ref(props.minHeight);
const expanded = ref(false);
let observer = null;

const split = computed(() => {
    if (!props.collapseQuotes) {
        return { visible: props.html, quoted: '', hasQuote: false };
    }
    if (props.html?.trim()) {
        return splitQuotedHtml(props.html);
    }
    if (props.text?.trim()) {
        return splitQuotedText(props.text);
    }
    return { visible: '', quoted: '', hasQuote: false };
});

const isHtmlMessage = computed(
    () => Boolean(props.html?.trim()) || !props.text?.trim(),
);

const displayHtml = computed(() => {
    if (!isHtmlMessage.value) {
        return '';
    }
    if (!split.value.hasQuote || expanded.value) {
        return props.html || '';
    }
    return split.value.visible;
});

const displayText = computed(() => {
    if (isHtmlMessage.value) {
        return '';
    }
    if (!split.value.hasQuote || expanded.value) {
        return props.text;
    }
    return split.value.visible;
});

const srcdoc = computed(() =>
    isHtmlMessage.value ? buildSrcdoc(displayHtml.value) : '',
);

/** Remount the frame when quote visibility changes so height is remeasured. */
const frameKey = computed(() =>
    `${expanded.value ? 'full' : 'short'}:${displayHtml.value.length}`,
);

watch(
    () => [props.html, props.text],
    () => {
        expanded.value = false;
    },
);

watch(srcdoc, () => {
    // Drop the previous tall size immediately; load/resize will grow as needed.
    height.value = props.minHeight;
});

function resize() {
    const el = frame.value;
    const doc = el?.contentDocument;
    if (!doc) {
        return;
    }

    // If the iframe is still tall from the expanded view, scrollHeight reports
    // the frame size — not the shorter collapsed content. Shrink first.
    el.style.height = '0px';
    const next = Math.max(props.minHeight, contentHeight(doc));
    height.value = next;
    el.style.height = `${next}px`;
}

function onLoad() {
    const doc = frame.value?.contentDocument;
    if (!doc) {
        return;
    }

    hardenLinks(doc);
    resize();

    observer?.disconnect();
    const Observer = frame.value.contentWindow?.ResizeObserver ?? window.ResizeObserver;
    if (Observer && doc.body) {
        observer = new Observer(() => {
            resize();
        });
        observer.observe(doc.body);
    }
    doc.querySelectorAll('img').forEach((img) =>
        img.addEventListener('load', resize, { once: true }),
    );
}

onBeforeUnmount(() => observer?.disconnect());

defineExpose({ resize });

watch(expanded, async () => {
    await nextTick();
    resize();
});
</script>

<template>
    <div class="w-full min-w-0 max-w-full space-y-2 overflow-x-auto">
        <iframe
            v-if="srcdoc"
            :key="frameKey"
            ref="frame"
            :srcdoc="srcdoc"
            :sandbox="SANDBOX"
            :title="title"
            referrerpolicy="no-referrer"
            class="block w-full min-w-0 max-w-full rounded-xl border-0 bg-white"
            :style="{ height: `${height}px`, colorScheme: 'light' }"
            data-testid="email-frame"
            @load="onLoad"
        />
        <p
            v-else-if="displayText"
            class="whitespace-pre-wrap break-words text-sm leading-relaxed text-zinc-300"
            data-testid="email-text"
        >
            {{ displayText }}
        </p>
        <button
            v-if="split.hasQuote"
            type="button"
            class="inline-flex items-center gap-1 rounded-md border border-zinc-300 bg-zinc-50 px-2 py-1 text-[11px] font-medium text-zinc-600 transition hover:border-zinc-400 hover:text-zinc-800 dark:border-zinc-800 dark:bg-zinc-900/80 dark:text-zinc-400 dark:hover:border-zinc-700 dark:hover:text-zinc-200"
            data-testid="toggle-quoted-text"
            @click="expanded = !expanded"
        >
            {{ expanded ? 'Hide quoted text' : 'Show quoted text' }}
        </button>
    </div>
</template>
