<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import SidebarLink from '@/Components/SidebarLink.vue';
import ToastContainer from '@/Components/ToastContainer.vue';
import { useTheme } from '@/composables/useTheme';
import {
    Building2,
    CreditCard,
    DollarSign,
    Globe2,
    LayoutDashboard,
    LogOut,
    Menu,
    Moon,
    Package,
    Server,
    Settings,
    Sun,
    X,
} from '@lucide/vue';

const page = usePage();
const mobileOpen = ref(false);
const user = computed(() => page.props.auth?.user);
const { theme, setTheme } = useTheme();

const nav = [
    { name: 'Overview', route: 'admin.dashboard', icon: LayoutDashboard },
    { name: 'Accounts', route: 'admin.accounts', icon: Building2 },
    { name: 'Subscriptions', route: 'admin.subscriptions', icon: CreditCard },
    { name: 'Plans', route: 'admin.plans', icon: Package },
    { name: 'Revenue', route: 'admin.revenue', icon: DollarSign },
    { name: 'Providers', route: 'admin.providers', icon: Server },
    { name: 'Subdomains', route: 'admin.subdomains', icon: Globe2 },
    { name: 'Settings', route: 'admin.settings', icon: Settings },
];

const pageTitle = computed(() => {
    const path = page.url.split('?')[0];
    if (path === '/admin' || path === '/admin/') return 'Overview';

    let best = null;
    let bestLen = 0;
    for (const item of nav) {
        if (item.route === 'admin.dashboard') continue;
        try {
            const target = new URL(route(item.route), 'http://local').pathname;
            if (
                (path === target || path.startsWith(`${target}/`)) &&
                target.length > bestLen
            ) {
                best = item.name;
                bestLen = target.length;
            }
        } catch {
            /* ignore */
        }
    }
    return best || 'Admin';
});

const isNavActive = (item) => {
    const path = page.url.split('?')[0];
    if (item.route === 'admin.dashboard') {
        return path === '/admin' || path === '/admin/';
    }
    try {
        const target = new URL(route(item.route), 'http://local').pathname;
        return path === target || path.startsWith(`${target}/`);
    } catch {
        return false;
    }
};

const signOut = () => router.post(route('logout'));

onMounted(() => {
    /* keep body scrollable */
});
onUnmounted(() => {
    mobileOpen.value = false;
});
</script>

<template>
    <div class="min-h-screen bg-black text-zinc-100">
        <div
            class="sticky top-0 z-30 flex items-center justify-between border-b border-zinc-900 bg-black/80 px-4 py-3 backdrop-blur-xl lg:hidden"
        >
            <button
                type="button"
                class="rounded-md border border-zinc-800 p-2 text-zinc-300"
                @click="mobileOpen = !mobileOpen"
            >
                <Menu v-if="!mobileOpen" :size="18" />
                <X v-else :size="18" />
            </button>
            <div class="flex items-center gap-2 text-sm font-medium">
                <img src="/images/brand/mark.png" alt="" class="h-7 w-7 rounded-md" />
                SaaS Admin
            </div>
            <Link
                :href="route('emails')"
                class="text-xs text-zinc-500 hover:text-zinc-300"
            >
                App
            </Link>
        </div>

        <div class="lg:flex">
            <aside
                class="fixed inset-y-0 left-0 z-40 flex h-dvh w-[248px] shrink-0 -translate-x-full flex-col border-r border-zinc-900 bg-zinc-950/90 backdrop-blur-xl transition duration-300 ease-out lg:sticky lg:top-0 lg:translate-x-0"
                :class="{ 'translate-x-0': mobileOpen }"
            >
                <div class="border-b border-zinc-900 px-4 py-4">
                    <div class="flex items-center gap-2.5">
                        <img src="/images/brand/mark.png" alt="" class="h-8 w-8 rounded-lg" />
                        <div class="min-w-0">
                            <div class="truncate text-sm font-semibold text-white">
                                MailDesk Admin
                            </div>
                            <div class="truncate text-[11px] text-zinc-500">
                                Platform control
                            </div>
                        </div>
                    </div>
                </div>

                <nav class="min-h-0 flex-1 space-y-0.5 overflow-y-auto px-2 py-3">
                    <SidebarLink
                        v-for="item in nav"
                        :key="item.route"
                        :href="route(item.route)"
                        :active="isNavActive(item)"
                        @click="mobileOpen = false"
                    >
                        <template #icon>
                            <component
                                :is="item.icon"
                                :size="16"
                                :stroke-width="1.75"
                            />
                        </template>
                        {{ item.name }}
                    </SidebarLink>
                </nav>

                <div class="shrink-0 space-y-2 border-t border-zinc-900 p-3">
                    <div
                        class="flex items-center justify-between rounded-xl border border-zinc-800 bg-black/40 px-3 py-2"
                    >
                        <span class="text-xs text-zinc-400">Appearance</span>
                        <div
                            class="inline-flex rounded-full border border-zinc-800 bg-black p-0.5"
                        >
                            <button
                                type="button"
                                class="rounded-full p-1.5 transition"
                                :class="
                                    theme === 'light'
                                        ? 'bg-zinc-800 text-cyan-300'
                                        : 'text-zinc-500 hover:text-zinc-300'
                                "
                                @click="setTheme('light')"
                            >
                                <Sun :size="13" />
                            </button>
                            <button
                                type="button"
                                class="rounded-full p-1.5 transition"
                                :class="
                                    theme === 'dark'
                                        ? 'bg-zinc-800 text-cyan-300'
                                        : 'text-zinc-500 hover:text-zinc-300'
                                "
                                @click="setTheme('dark')"
                            >
                                <Moon :size="13" />
                            </button>
                        </div>
                    </div>
                    <Link
                        :href="route('emails')"
                        class="flex w-full items-center justify-center rounded-lg border border-zinc-800 px-3 py-2 text-xs text-zinc-400 transition hover:border-zinc-700 hover:text-zinc-200"
                    >
                        Back to app
                    </Link>
                    <button
                        type="button"
                        class="flex w-full items-center gap-2 rounded-xl px-2 py-2 text-left transition hover:bg-zinc-900"
                        @click="signOut"
                    >
                        <span
                            class="flex h-8 w-8 items-center justify-center rounded-full bg-gradient-to-br from-cyan-400/40 to-zinc-700 text-[11px] font-semibold text-white"
                        >
                            {{ (user?.name || 'A').slice(0, 1).toUpperCase() }}
                        </span>
                        <span class="min-w-0 flex-1">
                            <span
                                class="block truncate text-xs font-medium text-zinc-200"
                                >{{ user?.name || 'Admin' }}</span
                            >
                            <span class="block truncate text-[11px] text-zinc-500"
                                >Sign out</span
                            >
                        </span>
                        <LogOut :size="14" class="text-zinc-500" />
                    </button>
                </div>
            </aside>

            <div
                v-if="mobileOpen"
                class="fixed inset-0 z-30 bg-black/70 backdrop-blur-sm lg:hidden"
                @click="mobileOpen = false"
            />

            <div class="flex min-h-screen min-w-0 flex-1 flex-col">
                <header
                    class="sticky top-0 z-20 hidden items-center justify-between border-b border-zinc-900/80 bg-black/80 px-6 py-3 backdrop-blur-xl lg:flex"
                >
                    <div class="text-sm text-zinc-500">
                        <span class="text-zinc-600">SaaS Admin</span>
                        <span class="mx-2 text-zinc-700">/</span>
                        <span class="font-medium text-zinc-200">{{
                            pageTitle
                        }}</span>
                    </div>
                    <Link
                        :href="route('emails')"
                        class="rounded-full border border-zinc-800 px-3 py-1.5 text-sm text-zinc-400 transition hover:border-zinc-700 hover:text-zinc-200"
                    >
                        Open app
                    </Link>
                </header>

                <main class="flex-1">
                    <div
                        class="mx-auto max-w-7xl animate-fade-in px-4 py-6 sm:px-6 lg:px-8"
                    >
                        <slot />
                    </div>
                </main>
            </div>
        </div>

        <ToastContainer />
    </div>
</template>
