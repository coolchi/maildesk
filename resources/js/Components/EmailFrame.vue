<script setup>
import { computed, onBeforeUnmount, ref } from 'vue';
import { SANDBOX, buildSrcdoc, contentHeight, hardenLinks } from '@/lib/emailFrame';

const props = defineProps({
    html: { type: String, default: '' },
    minHeight: { type: Number, default: 80 },
    title: { type: String, default: 'Email content' },
});

const frame = ref(null);
const height = ref(props.minHeight);
const srcdoc = computed(() => buildSrcdoc(props.html));
let observer = null;

function resize() {
    const doc = frame.value?.contentDocument;
    height.value = Math.max(props.minHeight, contentHeight(doc));
}

function onLoad() {
    const doc = frame.value?.contentDocument;
    if (!doc) return;

    hardenLinks(doc);
    resize();

    observer?.disconnect();
    const Observer = frame.value.contentWindow?.ResizeObserver ?? window.ResizeObserver;
    if (Observer && doc.body) {
        observer = new Observer(resize);
        observer.observe(doc.body);
    }
    // Late-loading images change the height too.
    doc.querySelectorAll('img').forEach((img) => img.addEventListener('load', resize, { once: true }));
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
        class="block w-full rounded-lg border-0 bg-white"
        :style="{ height: `${height}px`, colorScheme: 'light' }"
        data-testid="email-frame"
        @load="onLoad"
    />
</template>
