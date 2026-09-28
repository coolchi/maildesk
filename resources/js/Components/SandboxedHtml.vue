<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { SANDBOX, buildSrcdoc, contentHeight, hardenLinks } from '@/lib/emailFrame';

// Thumbnails: one letter width, no extra zoom. A pixel cap keeps a 1280px
// hero inside the column. max-width: 100% does not, and fitting that wider
// measurement shrinks some letters and leaves others full.
const FIT_STYLE = [
    'html,body{background:transparent !important;margin:0 !important;padding:0 !important;}',
    '#md-fit{width:600px;max-width:600px;}',
    '#md-fit img{display:block;width:auto !important;max-width:520px !important;height:auto !important;}',
].join('');

const props = defineProps({
    html: { type: String, default: '' },
    minHeight: { type: Number, default: 80 },
    maxHeight: { type: Number, default: null },
    autoResize: { type: Boolean, default: true },
    /** Letter-width thumbnail. The card scales the frame; this only caps images. */
    fit: { type: Boolean, default: false },
    title: { type: String, default: 'HTML preview' },
});

const frame = ref(null);
const height = ref(props.minHeight);
let observer = null;

const srcdoc = computed(() => buildSrcdoc(props.html, props.fit ? FIT_STYLE : ''));

watch(
    () => props.html,
    () => {
        height.value = props.minHeight;
    },
);

function resize() {
    const el = frame.value;
    const doc = el?.contentDocument;
    if (!doc) {
        return;
    }

    if (!props.autoResize) {
        return;
    }

    el.style.height = '0px';
    let next = Math.max(props.minHeight, contentHeight(doc));
    if (props.maxHeight !== null) {
        next = Math.min(next, props.maxHeight);
    }
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
    const Observer =
        frame.value.contentWindow?.ResizeObserver ?? window.ResizeObserver;
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
</script>

<template>
    <iframe
        ref="frame"
        :srcdoc="srcdoc"
        :sandbox="SANDBOX"
        :title="title"
        referrerpolicy="no-referrer"
        class="block w-full border-0 bg-white"
        :style="{
            height: `${autoResize ? height : minHeight}px`,
            colorScheme: 'light',
        }"
        data-testid="sandboxed-html"
        @load="onLoad"
    />
</template>
