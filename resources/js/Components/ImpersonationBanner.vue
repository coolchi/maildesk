<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { Eye, LogOut } from '@lucide/vue';
import { useToast } from '@/composables/useToast';

const page = usePage();
const toast = useToast();

const state = computed(() => page.props.impersonation || null);
const now = ref(Date.now());
const leaving = ref(false);
let timer = null;

const remainingMs = computed(() => {
    if (!state.value?.expires_at) return 0;
    return Math.max(0, new Date(state.value.expires_at).getTime() - now.value);
});

const countdown = computed(() => {
    const total = Math.floor(remainingMs.value / 1000);
    const m = String(Math.floor(total / 60)).padStart(2, '0');
    const s = String(total % 60).padStart(2, '0');
    return `${m}:${s}`;
});

const leave = () => {
    if (leaving.value) return;
    leaving.value = true;
    router.post(route('impersonate.leave'), {}, {
        onFinish: () => {
            leaving.value = false;
        },
    });
};

// Once the window has passed, reload so the server ends the session and
// returns the admin to the admin panel.
watch(remainingMs, (ms, prev) => {
    if (state.value && ms === 0 && prev > 0) {
        router.reload();
    }
});

// Blocked writes come back with a flash error; surface it once.
watch(
    () => page.props.flash?.error,
    (message) => {
        if (state.value && message && /impersonat/i.test(message)) {
            toast.error(message);
        }
    },
    { immediate: true },
);

onMounted(() => {
    timer = window.setInterval(() => {
        now.value = Date.now();
    }, 1000);
});

onUnmounted(() => {
    if (timer) window.clearInterval(timer);
});
</script>

<template>
    <div
        v-if="state?.active"
        class="sticky top-0 z-[60] flex flex-wrap items-center justify-center gap-x-3 gap-y-1 border-b border-amber-400/40 bg-amber-400 px-4 py-2 text-sm text-amber-950 shadow-lg shadow-amber-500/10"
        role="status"
        data-testid="impersonation-banner"
    >
        <Eye :size="16" class="shrink-0" />
        <span class="min-w-0 truncate">
            Viewing as
            <strong class="font-semibold">{{ state.user.name }}</strong>
            ({{ state.user.email }})
            <span class="mx-1 opacity-60">·</span>read-only
            <span class="mx-1 opacity-60">·</span>ends in
            <span class="font-mono tabular-nums">{{ countdown }}</span>
        </span>
        <button
            type="button"
            class="inline-flex items-center gap-1.5 rounded-md bg-amber-950 px-2.5 py-1 text-xs font-medium text-amber-100 transition hover:bg-black disabled:opacity-60"
            :disabled="leaving"
            @click="leave"
        >
            <LogOut :size="13" />
            {{ state.return_label || 'Return' }}
        </button>
    </div>
</template>
