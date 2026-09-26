<script setup>
import { ref, watch } from 'vue';
import { CheckCircle2, Loader2, PlugZap, XCircle } from '@lucide/vue';

const props = defineProps({
    provider: { type: Object, required: true },
});

const state = ref('idle'); // idle | testing | ok | failed | unsupported
const result = ref(null);

// Reset when switching providers.
watch(
    () => props.provider?.dbId,
    () => {
        state.value = 'idle';
        result.value = null;
    },
);

const run = async () => {
    if (!props.provider?.dbId || state.value === 'testing') return;
    state.value = 'testing';
    result.value = null;
    try {
        const { data } = await window.axios.post(route('admin.providers.test', props.provider.dbId));
        result.value = data;
        state.value = data.ok ? 'ok' : data.latency_ms === null ? 'unsupported' : 'failed';
    } catch (e) {
        const status = e?.response?.status;
        result.value = {
            ok: false,
            message:
                status === 429
                    ? 'Too many tests — wait a minute and try again.'
                    : e?.response?.data?.message || 'Could not run the test.',
        };
        state.value = 'failed';
    }
};
</script>

<template>
    <div class="inline-flex flex-col items-start gap-1" data-testid="provider-test">
        <button type="button" class="md-btn-ghost" :disabled="state === 'testing'" @click="run">
            <Loader2 v-if="state === 'testing'" :size="14" class="animate-spin" />
            <PlugZap v-else :size="14" />
            {{ state === 'testing' ? 'Testing…' : 'Test connection' }}
        </button>
        <p
            v-if="result"
            class="flex max-w-xs items-start gap-1 text-[11px]"
            :class="
                state === 'ok'
                    ? 'text-emerald-300'
                    : state === 'unsupported'
                      ? 'text-zinc-400'
                      : 'text-rose-300'
            "
        >
            <CheckCircle2 v-if="state === 'ok'" :size="12" class="mt-px shrink-0" />
            <XCircle v-else :size="12" class="mt-px shrink-0" />
            <span>
                {{ result.message }}
                <span v-if="result.latency_ms !== null && result.latency_ms !== undefined" class="text-zinc-500">
                    · {{ result.latency_ms }} ms
                </span>
            </span>
        </p>
    </div>
</template>
