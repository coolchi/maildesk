<script setup>
import { onMounted, onUnmounted } from 'vue';
import { PenSquare } from '@lucide/vue';
import { useComposeModal } from '@/composables/useComposeModal';
import { useTenant } from '@/composables/useTenant';
import { useToast } from '@/composables/useToast';

const { state, open } = useComposeModal();
const { canSend } = useTenant();
const toast = useToast();

const compose = () => {
    if (!canSend.value) {
        toast.error(
            'This workspace has no active mail provider. Reassign it in SaaS Admin.',
        );
        return;
    }
    if (state.open) {
        // Already drafting: bring the panel back up instead of starting over.
        state.minimized = false;
        return;
    }
    open();
};

// Gmail-style shortcut: press "c" anywhere outside a text field to compose.
const onKey = (e) => {
    if (e.key !== 'c' || e.metaKey || e.ctrlKey || e.altKey || e.shiftKey) return;
    const el = e.target;
    const tag = el?.tagName;
    if (
        el?.isContentEditable ||
        tag === 'INPUT' ||
        tag === 'TEXTAREA' ||
        tag === 'SELECT' ||
        document.querySelector('[aria-modal="true"]')
    ) {
        return;
    }
    e.preventDefault();
    compose();
};

onMounted(() => window.addEventListener('keydown', onKey));
onUnmounted(() => window.removeEventListener('keydown', onKey));
</script>

<template>
    <Transition
        enter-active-class="transition duration-200 ease-out"
        enter-from-class="translate-y-3 scale-90 opacity-0"
        enter-to-class="translate-y-0 scale-100 opacity-100"
        leave-active-class="transition duration-150 ease-in"
        leave-from-class="translate-y-0 scale-100 opacity-100"
        leave-to-class="translate-y-3 scale-90 opacity-0"
    >
        <button
            v-if="!state.open"
            type="button"
            class="md-btn-primary group fixed bottom-5 right-5 z-[80] hidden h-14 gap-2.5 rounded-2xl px-4 font-semibold shadow-lg shadow-black/30 transition hover:-translate-y-0.5 hover:shadow-xl focus:outline-none focus-visible:ring-2 focus-visible:ring-cyan-300 lg:inline-flex sm:bottom-6 sm:right-6 sm:px-5"
            :class="{ 'opacity-60': !canSend }"
            title="Compose (C)"
            aria-label="Compose new email"
            data-testid="floating-compose"
            @click="compose"
        >
            <PenSquare :size="20" class="transition group-hover:-rotate-6" />
            <span class="hidden sm:inline">Compose</span>
        </button>
    </Transition>
</template>
