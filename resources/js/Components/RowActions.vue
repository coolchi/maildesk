<script setup>
import { onMounted, onUnmounted, ref } from 'vue';
import { MoreHorizontal } from '@lucide/vue';

defineProps({
    items: {
        type: Array,
        required: true,
        // { id, label, icon?, danger?, disabled? }
    },
    align: {
        type: String,
        default: 'right',
        validator: (v) => ['left', 'right'].includes(v),
    },
});

const emit = defineEmits(['select']);

const open = ref(false);

const close = () => {
    open.value = false;
};

const toggle = (e) => {
    e.stopPropagation();
    e.preventDefault();
    open.value = !open.value;
};

const pick = (item, e) => {
    e.stopPropagation();
    e.preventDefault();
    if (item.disabled) return;
    open.value = false;
    emit('select', item);
};

onMounted(() => document.addEventListener('click', close));
onUnmounted(() => document.removeEventListener('click', close));
</script>

<template>
    <div class="relative inline-flex" @click.stop>
        <button
            type="button"
            class="rounded-md p-1.5 text-zinc-500 transition hover:bg-zinc-800 hover:text-zinc-200"
            :aria-expanded="open"
            aria-haspopup="menu"
            @click="toggle"
        >
            <MoreHorizontal :size="16" />
        </button>
        <div
            v-if="open"
            class="absolute z-30 mt-1 min-w-[11.5rem] overflow-hidden rounded-xl border border-zinc-800 bg-zinc-950 py-1 shadow-2xl shadow-black/60"
            :class="align === 'right' ? 'right-0' : 'left-0'"
            role="menu"
            @click.stop
        >
            <button
                v-for="item in items"
                :key="item.id"
                type="button"
                role="menuitem"
                class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm transition hover:bg-zinc-900 disabled:opacity-40"
                :class="
                    item.danger
                        ? 'text-rose-400'
                        : 'text-zinc-300'
                "
                :disabled="item.disabled"
                @click="pick(item, $event)"
            >
                <component
                    :is="item.icon"
                    v-if="item.icon"
                    :size="14"
                    :class="item.danger ? '' : 'text-zinc-500'"
                />
                {{ item.label }}
            </button>
        </div>
    </div>
</template>
