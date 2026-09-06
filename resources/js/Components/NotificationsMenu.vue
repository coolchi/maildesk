<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useNotifications } from '@/composables/useNotifications';
import {
    Bell,
    CheckCheck,
    Globe,
    Inbox,
    ScrollText,
    Webhook,
} from '@lucide/vue';

const open = ref(false);
const {
    notifications,
    unreadCount,
    markRead,
    markAllRead,
} = useNotifications();

const iconFor = (type) => {
    if (type === 'inbox') return Inbox;
    if (type === 'bounce') return ScrollText;
    if (type === 'domain') return Globe;
    if (type === 'webhook') return Webhook;
    return Bell;
};

const sorted = computed(() =>
    [...notifications].sort((a, b) => Number(b.unread) - Number(a.unread)),
);

const close = () => {
    open.value = false;
};

const toggle = (e) => {
    e.stopPropagation();
    open.value = !open.value;
};

const onDocClick = () => close();

onMounted(() => document.addEventListener('click', onDocClick));
onUnmounted(() => document.removeEventListener('click', onDocClick));

const onItemClick = (item) => {
    markRead(item.id);
    close();
};
</script>

<template>
    <div class="relative" @click.stop>
        <button
            type="button"
            class="relative inline-flex h-9 w-9 items-center justify-center rounded-full border border-zinc-800 text-zinc-400 transition hover:border-zinc-700 hover:text-zinc-200"
            :aria-expanded="open"
            aria-label="Notifications"
            @click="toggle"
        >
            <Bell :size="15" />
            <span
                v-if="unreadCount"
                class="absolute -right-0.5 -top-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-cyan-400 px-1 text-[10px] font-semibold text-zinc-950"
            >
                {{ unreadCount > 9 ? '9+' : unreadCount }}
            </span>
        </button>

        <Transition
            enter-active-class="transition duration-150 ease-out"
            enter-from-class="translate-y-1 opacity-0"
            enter-to-class="translate-y-0 opacity-100"
            leave-active-class="transition duration-100 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="open"
                class="absolute right-0 z-50 mt-2 w-[360px] overflow-hidden rounded-xl border border-zinc-800 bg-zinc-950 shadow-2xl shadow-black/50"
            >
                <div
                    class="flex items-center justify-between border-b border-zinc-900 px-4 py-3"
                >
                    <div>
                        <p class="text-sm font-medium text-white">
                            Notifications
                        </p>
                        <p class="text-[11px] text-zinc-500">
                            {{
                                unreadCount
                                    ? `${unreadCount} unread`
                                    : 'You\'re all caught up'
                            }}
                        </p>
                    </div>
                    <button
                        v-if="unreadCount"
                        type="button"
                        class="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-[11px] text-cyan-300 transition hover:bg-cyan-400/10"
                        @click="markAllRead"
                    >
                        <CheckCheck :size="12" />
                        Mark all read
                    </button>
                </div>

                <ul class="max-h-[360px] overflow-y-auto">
                    <li
                        v-for="item in sorted"
                        :key="item.id"
                        class="border-b border-zinc-900/80 last:border-0"
                    >
                        <Link
                            :href="item.href"
                            class="flex gap-3 px-4 py-3 transition hover:bg-white/[0.03]"
                            :class="item.unread ? 'bg-cyan-400/[0.04]' : ''"
                            @click="onItemClick(item)"
                        >
                            <span
                                class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-zinc-800 bg-zinc-900 text-zinc-400"
                                :class="
                                    item.unread
                                        ? 'border-cyan-400/30 text-cyan-300'
                                        : ''
                                "
                            >
                                <component
                                    :is="iconFor(item.type)"
                                    :size="14"
                                />
                            </span>
                            <span class="min-w-0 flex-1">
                                <span
                                    class="flex items-start justify-between gap-2"
                                >
                                    <span
                                        class="truncate text-sm"
                                        :class="
                                            item.unread
                                                ? 'font-medium text-white'
                                                : 'text-zinc-300'
                                        "
                                    >
                                        {{ item.title }}
                                    </span>
                                    <span
                                        v-if="item.unread"
                                        class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-cyan-400"
                                    />
                                </span>
                                <span
                                    class="mt-0.5 block truncate text-xs text-zinc-500"
                                >
                                    {{ item.body }}
                                </span>
                                <span
                                    class="mt-1 block text-[11px] text-zinc-600"
                                >
                                    {{ item.time }}
                                </span>
                            </span>
                        </Link>
                    </li>
                </ul>

                <div class="border-t border-zinc-900 px-4 py-2.5">
                    <Link
                        :href="route('inbox')"
                        class="text-xs text-cyan-300 transition hover:text-cyan-200"
                        @click="close"
                    >
                        Open inbox →
                    </Link>
                </div>
            </div>
        </Transition>
    </div>
</template>
