<script setup>
import { computed, onMounted, onUnmounted, watch } from 'vue';
import { X } from '@lucide/vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    title: { type: String, default: '' },
    description: { type: String, default: '' },
    maxWidth: {
        type: String,
        default: 'md',
        validator: (v) => ['sm', 'md', 'lg', 'xl'].includes(v),
    },
});

const emit = defineEmits(['close']);

const maxClass = computed(
    () =>
        ({
            sm: 'max-w-sm',
            md: 'max-w-md',
            lg: 'max-w-lg',
            xl: 'max-w-xl',
        })[props.maxWidth],
);

const onKey = (e) => {
    if (e.key === 'Escape' && props.show) emit('close');
};

onMounted(() => window.addEventListener('keydown', onKey));
onUnmounted(() => window.removeEventListener('keydown', onKey));

watch(
    () => props.show,
    (open) => {
        document.body.style.overflow = open ? 'hidden' : '';
    },
);
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
                v-if="show"
                class="fixed inset-0 z-[80] flex items-end justify-center bg-black/70 p-4 backdrop-blur-md sm:items-center"
                @click.self="emit('close')"
            >
                <Transition
                    enter-active-class="transition duration-200 ease-out"
                    enter-from-class="translate-y-4 opacity-0 sm:translate-y-0 sm:scale-95"
                    enter-to-class="translate-y-0 opacity-100 sm:scale-100"
                    leave-active-class="transition duration-150 ease-in"
                    leave-from-class="translate-y-0 opacity-100 sm:scale-100"
                    leave-to-class="translate-y-4 opacity-0 sm:scale-95"
                    appear
                >
                    <div
                        v-if="show"
                        class="w-full rounded-2xl border border-zinc-800 bg-zinc-950 shadow-2xl"
                        :class="maxClass"
                        role="dialog"
                        aria-modal="true"
                    >
                        <div
                            class="flex items-start justify-between gap-3 border-b border-zinc-800 px-5 py-4"
                        >
                            <div>
                                <h2
                                    v-if="title"
                                    class="text-base font-semibold text-white"
                                >
                                    {{ title }}
                                </h2>
                                <p
                                    v-if="description"
                                    class="mt-1 text-sm text-zinc-400"
                                >
                                    {{ description }}
                                </p>
                            </div>
                            <button
                                type="button"
                                class="rounded-lg p-1.5 text-zinc-500 hover:bg-zinc-900 hover:text-white"
                                @click="emit('close')"
                            >
                                <X :size="16" />
                            </button>
                        </div>
                        <div class="px-5 py-4">
                            <slot />
                        </div>
                        <div
                            v-if="$slots.footer"
                            class="flex items-center justify-end gap-2 border-t border-zinc-800 px-5 py-4"
                        >
                            <slot name="footer" />
                        </div>
                    </div>
                </Transition>
            </div>
        </Transition>
    </Teleport>
</template>
