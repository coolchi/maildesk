<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { useCommandPalette } from '@/composables/useCommandPalette';
import { useComposeModal } from '@/composables/useComposeModal';
import {
    BarChart3,
    BookOpen,
    Globe,
    Inbox,
    KeyRound,
    LayoutTemplate,
    Mail,
    Megaphone,
    PenSquare,
    Search,
    Settings,
    Shield,
    Users,
    Webhook,
    Workflow,
    Send,
    MailX,
} from '@lucide/vue';

const page = usePage();
const { state, close } = useCommandPalette();
const { open: openCompose } = useComposeModal();
const query = ref('');
const active = ref(0);

const baseCommands = [
    { name: 'Emails', route: 'emails', icon: Mail, group: 'Mail' },
    { name: 'Inbox', route: 'inbox', icon: Inbox, group: 'Mail' },
    { name: 'Compose', action: 'compose', icon: PenSquare, group: 'Mail' },
    { name: 'Sent', route: 'sent', icon: Send, group: 'Mail' },
    { name: 'Bounced', route: 'bounced', icon: MailX, group: 'Mail' },
    { name: 'Users', route: 'users', icon: Users, group: 'Mail' },
    { name: 'Broadcasts', route: 'broadcasts', icon: Megaphone, group: 'Engage' },
    { name: 'Automations', route: 'automations', icon: Workflow, group: 'Engage' },
    { name: 'Templates', route: 'templates', icon: LayoutTemplate, group: 'Engage' },
    { name: 'Audience', route: 'audience', icon: Users, group: 'Engage' },
    { name: 'Metrics', route: 'metrics', icon: BarChart3, group: 'Ops' },
    { name: 'Domains', route: 'domains', icon: Globe, group: 'Ops' },
    { name: 'API Keys', route: 'api-keys', icon: KeyRound, group: 'Developers' },
    { name: 'Webhooks', route: 'webhooks', icon: Webhook, group: 'Developers' },
    { name: 'Docs', route: 'docs', icon: BookOpen, group: 'Developers' },
    { name: 'Settings', route: 'settings', params: 'usage', icon: Settings, group: 'Developers' },
];

const commands = computed(() => {
    const list = [...baseCommands];
    if (page.props.auth?.user?.is_platform_admin) {
        list.push({
            name: 'SaaS Admin',
            route: 'admin.dashboard',
            icon: Shield,
            group: 'Admin',
        });
    }
    return list;
});

const filtered = computed(() => {
    const q = query.value.trim().toLowerCase();
    if (!q) return commands.value;
    return commands.value.filter(
        (c) =>
            c.name.toLowerCase().includes(q) ||
            c.group.toLowerCase().includes(q),
    );
});

watch(
    () => state.open,
    (open) => {
        if (open) {
            query.value = '';
            active.value = 0;
            document.body.style.overflow = 'hidden';
        } else {
            document.body.style.overflow = '';
        }
    },
);

watch(filtered, () => {
    active.value = 0;
});

const run = (cmd) => {
    close();
    if (cmd.action === 'compose') {
        openCompose();
        return;
    }
    router.visit(
        cmd.params !== undefined
            ? route(cmd.route, cmd.params)
            : route(cmd.route),
    );
};

const onKey = (e) => {
    if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        state.open = !state.open;
        return;
    }
    if (!state.open) return;
    if (e.key === 'Escape') {
        e.preventDefault();
        close();
    } else if (e.key === 'ArrowDown') {
        e.preventDefault();
        active.value = Math.min(active.value + 1, filtered.value.length - 1);
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        active.value = Math.max(active.value - 1, 0);
    } else if (e.key === 'Enter' && filtered.value[active.value]) {
        e.preventDefault();
        run(filtered.value[active.value]);
    }
};

onMounted(() => window.addEventListener('keydown', onKey));
onUnmounted(() => {
    window.removeEventListener('keydown', onKey);
    document.body.style.overflow = '';
});
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition duration-150 ease-out"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition duration-100 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="state.open"
                class="fixed inset-0 z-[100] flex items-start justify-center bg-black/70 p-4 pt-[12vh] backdrop-blur-sm"
                @click.self="close"
            >
                <div
                    class="w-full max-w-lg overflow-hidden rounded-2xl border border-zinc-800 bg-zinc-950 shadow-2xl"
                    role="dialog"
                    aria-modal="true"
                >
                    <div class="flex items-center gap-2 border-b border-zinc-800 px-4 py-3">
                        <Search :size="16" class="text-zinc-500" />
                        <input
                            v-model="query"
                            type="text"
                            class="w-full bg-transparent text-sm text-white outline-none placeholder:text-zinc-500"
                            placeholder="Search pages…"
                            autofocus
                        />
                        <kbd
                            class="rounded border border-zinc-800 px-1.5 py-0.5 text-[10px] text-zinc-500"
                            >esc</kbd
                        >
                    </div>
                    <ul class="max-h-80 overflow-y-auto py-2">
                        <li v-if="!filtered.length" class="px-4 py-6 text-center text-sm text-zinc-500">
                            No matches
                        </li>
                        <li
                            v-for="(cmd, i) in filtered"
                            :key="cmd.route || cmd.action"
                        >
                            <button
                                type="button"
                                class="flex w-full items-center gap-3 px-4 py-2.5 text-left text-sm transition"
                                :class="
                                    i === active
                                        ? 'bg-cyan-400/10 text-white'
                                        : 'text-zinc-300 hover:bg-zinc-900'
                                "
                                @mouseenter="active = i"
                                @click="run(cmd)"
                            >
                                <component
                                    :is="cmd.icon"
                                    :size="16"
                                    class="text-cyan-300"
                                />
                                <span class="flex-1">{{ cmd.name }}</span>
                                <span class="text-[11px] text-zinc-600">{{
                                    cmd.group
                                }}</span>
                            </button>
                        </li>
                    </ul>
                    <div
                        class="flex items-center justify-between border-t border-zinc-900 px-4 py-2 text-[11px] text-zinc-600"
                    >
                        <span>↑↓ navigate · ↵ open</span>
                        <span>⌘K</span>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
