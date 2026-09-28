<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    BookOpen,
    Check,
    ChevronDown,
    ExternalLink,
    HelpCircle,
    LogOut,
    Moon,
    Plus,
    Shield,
    Sun,
    Trash2,
    X,
} from '@lucide/vue';
import { usePlansModal } from '@/composables/usePlansModal';
import { useTenant } from '@/composables/useTenant';
import { useTheme } from '@/composables/useTheme';
import { useToast } from '@/composables/useToast';

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    groups: {
        type: Array,
        default: () => [],
    },
    /** Route names already on the bottom tab bar — omit from the sheet grid. */
    excludeRoutes: {
        type: Array,
        default: () => [],
    },
});

const emit = defineEmits(['close', 'create-workspace']);

const page = usePage();
const user = computed(() => page.props.auth?.user);
const abilities = computed(() => page.props.auth?.abilities || {});
const { open: openPlans } = usePlansModal();
const { theme, setTheme } = useTheme();
const toast = useToast();
const {
    workspaces,
    activeWorkspace,
    activeWorkspaceId,
    selectWorkspace: switchWorkspace,
    hostLocked,
} = useTenant();

const teamOpen = ref(false);

const colorMap = {
    cyan: 'bg-cyan-400/20 text-cyan-300',
    violet: 'bg-violet-400/20 text-violet-300',
    emerald: 'bg-emerald-400/20 text-emerald-300',
    amber: 'bg-amber-400/20 text-amber-300',
};

const excluded = computed(() => new Set(props.excludeRoutes));

const sheetGroups = computed(() =>
    props.groups
        .map((group) => ({
            ...group,
            items: group.items.filter(
                (item) => item.route && !excluded.value.has(item.route),
            ),
        }))
        .filter((group) => group.items.length > 0),
);

const navHref = (item) => {
    if (!item.route) {
        return '#';
    }
    if (item.params !== undefined) {
        return route(item.route, item.params);
    }
    return route(item.route);
};

const isActive = (item) => {
    const path = page.url.split('?')[0];
    if (item.route === 'settings') {
        return path.startsWith('/settings');
    }
    try {
        const target = new URL(navHref(item), 'http://local').pathname;
        return path === target || path.startsWith(`${target}/`);
    } catch {
        return false;
    }
};

const close = () => emit('close');

const onKey = (e) => {
    if (e.key === 'Escape' && props.show) {
        close();
    }
};

onMounted(() => window.addEventListener('keydown', onKey));
onUnmounted(() => window.removeEventListener('keydown', onKey));

watch(
    () => props.show,
    (open) => {
        document.body.style.overflow = open ? 'hidden' : '';
        if (!open) {
            teamOpen.value = false;
        }
    },
);

watch(
    () => page.url,
    () => {
        if (props.show) {
            close();
        }
    },
);

const selectWorkspace = (ws) => {
    teamOpen.value = false;
    if (ws.id === activeWorkspaceId.value) {
        return;
    }
    switchWorkspace(ws.id);
    toast.success(`Switching to ${ws.name}…`);
    close();
};

const signOut = () => {
    close();
    router.post(route('logout'), {
        _token: page.props.csrf_token,
    });
};
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
                class="fixed inset-0 z-[70] bg-black/70 backdrop-blur-md lg:hidden"
                data-testid="mobile-more-backdrop"
                @click="close"
            />
        </Transition>

        <Transition
            enter-active-class="transition duration-250 ease-out"
            enter-from-class="translate-y-full"
            enter-to-class="translate-y-0"
            leave-active-class="transition duration-200 ease-in"
            leave-from-class="translate-y-0"
            leave-to-class="translate-y-full"
        >
            <div
                v-if="show"
                class="fixed inset-x-0 bottom-0 z-[75] max-h-[85dvh] overflow-hidden rounded-t-3xl border border-zinc-800 border-b-0 bg-zinc-950 shadow-2xl lg:hidden"
                style="padding-bottom: env(safe-area-inset-bottom, 0px)"
                role="dialog"
                aria-modal="true"
                aria-label="More"
                data-testid="mobile-more-sheet"
                @click.stop
            >
                <div class="flex justify-center pt-3">
                    <span class="h-1 w-10 rounded-full bg-zinc-700" aria-hidden="true" />
                </div>

                <div class="flex items-center justify-between px-4 pb-2 pt-3">
                    <h2 class="text-base font-semibold text-white">Menu</h2>
                    <button
                        type="button"
                        class="flex h-9 w-9 items-center justify-center rounded-full border border-zinc-800 text-zinc-400 transition active:scale-95"
                        aria-label="Close menu"
                        data-testid="mobile-more-close"
                        @click="close"
                    >
                        <X :size="18" />
                    </button>
                </div>

                <div class="md-hide-scrollbar max-h-[calc(85dvh-4rem)] overflow-y-auto px-4 pb-4">
                    <!-- Workspace -->
                    <div v-if="activeWorkspace" class="relative mb-4">
                        <button
                            type="button"
                            class="flex w-full items-center gap-3 rounded-2xl border border-zinc-800 bg-zinc-900/60 px-3 py-3 text-left transition active:bg-zinc-900"
                            data-testid="mobile-more-workspace"
                            @click.stop="teamOpen = !teamOpen"
                        >
                            <span
                                class="flex h-11 w-11 items-center justify-center rounded-xl text-sm font-semibold"
                                :class="colorMap[activeWorkspace.color] || colorMap.cyan"
                            >
                                {{ activeWorkspace.name.slice(0, 1) }}
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="flex items-center gap-1.5">
                                    <span class="truncate text-sm font-semibold text-white">
                                        {{ activeWorkspace.name }}
                                    </span>
                                    <span
                                        class="rounded-full bg-cyan-400/15 px-1.5 py-0.5 text-[10px] font-medium text-cyan-300"
                                    >
                                        {{ activeWorkspace.plan }}
                                    </span>
                                </span>
                                <span class="mt-0.5 block truncate text-xs text-zinc-500">
                                    {{ activeWorkspace.host || activeWorkspace.email }}
                                </span>
                            </span>
                            <ChevronDown
                                :size="16"
                                class="text-zinc-500 transition"
                                :class="{ 'rotate-180 text-cyan-300': teamOpen }"
                            />
                        </button>

                        <div
                            v-if="teamOpen"
                            class="mt-2 overflow-hidden rounded-2xl border border-zinc-800 bg-black"
                        >
                            <button
                                v-for="ws in workspaces"
                                :key="ws.id"
                                type="button"
                                class="flex w-full items-center gap-3 px-3 py-2.5 text-left transition hover:bg-zinc-900"
                                @click="selectWorkspace(ws)"
                            >
                                <span
                                    class="flex h-8 w-8 items-center justify-center rounded-lg text-xs font-semibold"
                                    :class="colorMap[ws.color] || colorMap.cyan"
                                >
                                    {{ ws.name.slice(0, 1) }}
                                </span>
                                <span class="min-w-0 flex-1 truncate text-sm text-zinc-200">
                                    {{ ws.name }}
                                </span>
                                <Check
                                    v-if="ws.id === activeWorkspaceId"
                                    :size="16"
                                    class="text-cyan-300"
                                />
                            </button>
                            <button
                                v-if="!hostLocked"
                                type="button"
                                class="flex w-full items-center gap-2 border-t border-zinc-900 px-3 py-2.5 text-sm text-cyan-300"
                                @click="
                                    teamOpen = false;
                                    emit('create-workspace');
                                    close();
                                "
                            >
                                <Plus :size="16" />
                                Create account
                            </button>
                            <button
                                type="button"
                                class="flex w-full items-center gap-2 border-t border-zinc-900 px-3 py-2.5 text-sm text-zinc-300"
                                @click="
                                    teamOpen = false;
                                    openPlans();
                                    close();
                                "
                            >
                                Upgrade plan
                            </button>
                        </div>
                    </div>

                    <!-- Icon grids by group -->
                    <section
                        v-for="group in sheetGroups"
                        :key="group.label"
                        class="mb-5"
                    >
                        <h3
                            class="mb-2 px-1 text-[11px] font-semibold uppercase tracking-wider text-zinc-500"
                        >
                            {{ group.label }}
                        </h3>
                        <div class="grid grid-cols-4 gap-2">
                            <Link
                                v-for="item in group.items"
                                :key="item.name"
                                :href="navHref(item)"
                                class="flex flex-col items-center gap-1.5 rounded-2xl px-1 py-2.5 text-center transition active:bg-zinc-900"
                                :class="
                                    isActive(item)
                                        ? 'bg-cyan-400/10 text-cyan-300'
                                        : 'text-zinc-300'
                                "
                                :data-testid="`mobile-more-${item.route}`"
                                @click="close"
                            >
                                <span
                                    class="flex h-12 w-12 items-center justify-center rounded-2xl border"
                                    :class="
                                        isActive(item)
                                            ? 'border-cyan-400/40 bg-cyan-400/15'
                                            : 'border-zinc-800 bg-zinc-900/80'
                                    "
                                >
                                    <component
                                        :is="item.icon"
                                        :size="22"
                                        :stroke-width="1.85"
                                    />
                                </span>
                                <span class="line-clamp-2 w-full text-[11px] font-medium leading-tight">
                                    {{ item.name }}
                                </span>
                            </Link>
                        </div>
                    </section>

                    <!-- Extra destinations -->
                    <section class="mb-5">
                        <h3
                            class="mb-2 px-1 text-[11px] font-semibold uppercase tracking-wider text-zinc-500"
                        >
                            Account
                        </h3>
                        <div class="grid grid-cols-4 gap-2">
                            <Link
                                v-if="abilities.inbox && !abilities.manage"
                                :href="route('trash')"
                                class="flex flex-col items-center gap-1.5 rounded-2xl px-1 py-2.5 text-center text-zinc-300 transition active:bg-zinc-900"
                                data-testid="mobile-more-trash"
                                @click="close"
                            >
                                <span
                                    class="flex h-12 w-12 items-center justify-center rounded-2xl border border-zinc-800 bg-zinc-900/80"
                                >
                                    <Trash2 :size="22" :stroke-width="1.85" />
                                </span>
                                <span class="text-[11px] font-medium">Trash</span>
                            </Link>
                            <Link
                                v-if="abilities.manage"
                                :href="route('docs')"
                                class="flex flex-col items-center gap-1.5 rounded-2xl px-1 py-2.5 text-center text-zinc-300 transition active:bg-zinc-900"
                                @click="close"
                            >
                                <span
                                    class="flex h-12 w-12 items-center justify-center rounded-2xl border border-zinc-800 bg-zinc-900/80"
                                >
                                    <BookOpen :size="22" :stroke-width="1.85" />
                                </span>
                                <span class="text-[11px] font-medium">Docs</span>
                            </Link>
                            <Link
                                v-if="abilities.manage"
                                :href="route('help')"
                                class="flex flex-col items-center gap-1.5 rounded-2xl px-1 py-2.5 text-center text-zinc-300 transition active:bg-zinc-900"
                                @click="close"
                            >
                                <span
                                    class="flex h-12 w-12 items-center justify-center rounded-2xl border border-zinc-800 bg-zinc-900/80"
                                >
                                    <HelpCircle :size="22" :stroke-width="1.85" />
                                </span>
                                <span class="text-[11px] font-medium">Help</span>
                            </Link>
                            <Link
                                v-if="user?.is_platform_admin"
                                :href="route('admin.dashboard')"
                                class="flex flex-col items-center gap-1.5 rounded-2xl px-1 py-2.5 text-center text-zinc-300 transition active:bg-zinc-900"
                                @click="close"
                            >
                                <span
                                    class="flex h-12 w-12 items-center justify-center rounded-2xl border border-cyan-400/30 bg-cyan-400/10 text-cyan-300"
                                >
                                    <Shield :size="22" :stroke-width="1.85" />
                                </span>
                                <span class="text-[11px] font-medium">Admin</span>
                            </Link>
                            <Link
                                href="/"
                                class="flex flex-col items-center gap-1.5 rounded-2xl px-1 py-2.5 text-center text-zinc-300 transition active:bg-zinc-900"
                                @click="close"
                            >
                                <span
                                    class="flex h-12 w-12 items-center justify-center rounded-2xl border border-zinc-800 bg-zinc-900/80"
                                >
                                    <ExternalLink :size="22" :stroke-width="1.85" />
                                </span>
                                <span class="text-[11px] font-medium">Site</span>
                            </Link>
                        </div>
                    </section>

                    <!-- Appearance + sign out -->
                    <div class="space-y-2 rounded-2xl border border-zinc-800 bg-zinc-900/40 p-3">
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-sm text-zinc-300">Appearance</span>
                            <div class="inline-flex rounded-full border border-zinc-800 bg-black p-0.5">
                                <button
                                    type="button"
                                    class="rounded-full p-2 transition"
                                    :class="
                                        theme === 'light'
                                            ? 'bg-zinc-800 text-cyan-300'
                                            : 'text-zinc-500'
                                    "
                                    aria-label="Light theme"
                                    @click="setTheme('light')"
                                >
                                    <Sun :size="16" />
                                </button>
                                <button
                                    type="button"
                                    class="rounded-full p-2 transition"
                                    :class="
                                        theme === 'dark'
                                            ? 'bg-zinc-800 text-cyan-300'
                                            : 'text-zinc-500'
                                    "
                                    aria-label="Dark theme"
                                    @click="setTheme('dark')"
                                >
                                    <Moon :size="16" />
                                </button>
                            </div>
                        </div>
                        <button
                            type="button"
                            class="flex w-full items-center justify-center gap-2 rounded-xl border border-rose-500/30 bg-rose-500/10 px-3 py-3 text-sm font-medium text-rose-300 transition active:bg-rose-500/20"
                            data-testid="mobile-more-sign-out"
                            @click="signOut"
                        >
                            <LogOut :size="16" />
                            Sign out
                        </button>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
