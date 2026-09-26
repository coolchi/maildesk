<script setup>
import { nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { MoreHorizontal } from '@lucide/vue';
import {
    claimRowActions,
    registerRowActions,
    releaseRowActions,
    unregisterRowActions,
} from '@/composables/rowActionsRegistry';

const props = defineProps({
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
const trigger = ref(null);
const menu = ref(null);
const coords = ref({ top: 0, left: 0 });
let registryId = null;

const close = () => {
    open.value = false;
    if (registryId !== null) {
        releaseRowActions(registryId);
    }
};

const placeMenu = async () => {
    await nextTick();
    const el = trigger.value;
    const panel = menu.value;
    if (!el || !panel) {
        return;
    }

    const rect = el.getBoundingClientRect();
    const menuRect = panel.getBoundingClientRect();
    const gap = 4;
    let top = rect.bottom + gap;
    let left =
        props.align === 'right'
            ? rect.right - menuRect.width
            : rect.left;

    if (top + menuRect.height > window.innerHeight - 8) {
        top = Math.max(8, rect.top - menuRect.height - gap);
    }
    left = Math.min(
        Math.max(8, left),
        window.innerWidth - menuRect.width - 8,
    );

    coords.value = { top, left };
};

const toggle = async (e) => {
    e.stopPropagation();
    e.preventDefault();

    if (open.value) {
        close();
        return;
    }

    claimRowActions(registryId);
    open.value = true;
    await placeMenu();
};

const pick = (item, e) => {
    e.stopPropagation();
    e.preventDefault();
    if (item.disabled) {
        return;
    }
    close();
    emit('select', item);
};

const onDocClick = (e) => {
    if (!open.value) {
        return;
    }
    const t = e.target;
    if (trigger.value?.contains(t) || menu.value?.contains(t)) {
        return;
    }
    close();
};

const onKey = (e) => {
    if (e.key === 'Escape') {
        close();
    }
};

onMounted(() => {
    registryId = registerRowActions(close);
    document.addEventListener('click', onDocClick);
    document.addEventListener('keydown', onKey);
    window.addEventListener('resize', close);
    window.addEventListener('scroll', close, true);
});

onUnmounted(() => {
    if (registryId !== null) {
        unregisterRowActions(registryId);
    }
    document.removeEventListener('click', onDocClick);
    document.removeEventListener('keydown', onKey);
    window.removeEventListener('resize', close);
    window.removeEventListener('scroll', close, true);
});

watch(open, async (isOpen) => {
    if (isOpen) {
        await placeMenu();
    }
});
</script>

<template>
    <div class="relative inline-flex">
        <button
            ref="trigger"
            type="button"
            class="rounded-md p-1.5 text-zinc-500 transition hover:bg-zinc-800 hover:text-zinc-200"
            :aria-expanded="open"
            aria-haspopup="menu"
            @click="toggle"
        >
            <MoreHorizontal :size="16" />
        </button>

        <Teleport to="body">
            <div
                v-if="open"
                ref="menu"
                class="fixed z-[80] min-w-[11.5rem] overflow-hidden rounded-xl border border-zinc-800 bg-zinc-950 py-1 shadow-2xl shadow-black/60"
                :style="{
                    top: `${coords.top}px`,
                    left: `${coords.left}px`,
                }"
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
                        item.danger ? 'text-rose-400' : 'text-zinc-300'
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
        </Teleport>
    </div>
</template>
