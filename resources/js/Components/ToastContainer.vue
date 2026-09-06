<script setup>
import { CheckCircle2, CircleAlert, Info, X } from '@lucide/vue';
import { useToast } from '@/composables/useToast';

const { toasts, dismiss } = useToast();

const icon = {
    success: CheckCircle2,
    error: CircleAlert,
    info: Info,
};
</script>

<template>
    <div
        class="pointer-events-none fixed right-4 top-4 z-[100] flex w-full max-w-sm flex-col gap-2"
    >
        <TransitionGroup
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="translate-x-4 opacity-0"
            enter-to-class="translate-x-0 opacity-100"
            leave-active-class="transition duration-150 ease-in"
            leave-from-class="translate-x-0 opacity-100"
            leave-to-class="translate-x-4 opacity-0"
        >
            <div
                v-for="toast in toasts"
                :key="toast.id"
                class="md-toast pointer-events-auto flex items-start gap-3 rounded-xl border px-4 py-3 text-sm shadow-xl backdrop-blur"
                :data-type="toast.type"
            >
                <component
                    :is="icon[toast.type] || Info"
                    :size="18"
                    class="mt-0.5 shrink-0"
                />
                <div class="min-w-0 flex-1 leading-snug">{{ toast.message }}</div>
                <button
                    type="button"
                    class="shrink-0 rounded p-0.5 opacity-70 hover:opacity-100"
                    @click="dismiss(toast.id)"
                >
                    <X :size="14" />
                </button>
            </div>
        </TransitionGroup>
    </div>
</template>
