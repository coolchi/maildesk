<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import SidebarLink from '@/Components/SidebarLink.vue';
import ToastContainer from '@/Components/ToastContainer.vue';
import PlansModal from '@/Components/PlansModal.vue';
import ComposeModal from '@/Components/ComposeModal.vue';
import FloatingComposeButton from '@/Components/FloatingComposeButton.vue';
import MobileTabBar from '@/Components/MobileTabBar.vue';
import MobileMoreSheet from '@/Components/MobileMoreSheet.vue';
import CommandPalette from '@/Components/CommandPalette.vue';
import OnboardingModal from '@/Components/OnboardingModal.vue';
import NotificationsMenu from '@/Components/NotificationsMenu.vue';
import Modal from '@/Components/Modal.vue';
import CreateAccountFields from '@/Components/CreateAccountFields.vue';
import ImpersonationBanner from '@/Components/ImpersonationBanner.vue';
import { useTenant } from '@/composables/useTenant';
import { usePlansModal } from '@/composables/usePlansModal';
import { useComposeModal } from '@/composables/useComposeModal';
import { useOnboarding } from '@/composables/useOnboarding';
import { useCommandPalette } from '@/composables/useCommandPalette';
import { useNotifications } from '@/composables/useNotifications';
import { useInboxLive } from '@/composables/useInboxLive';
import { setInboxSoundPreference, unlockInboxAudio } from '@/composables/useInboxSound';
import { useTheme } from '@/composables/useTheme';
import { useToast } from '@/composables/useToast';
import { useMobileChrome } from '@/composables/useMobileChrome';
import {
    Mail,
    Inbox,
    Megaphone,
    Workflow,
    LayoutTemplate,
    Users,
    UsersRound,
    UserCog,
    User,
    BarChart3,
    Globe,
    ScrollText,
    KeyRound,
    Webhook,
    BookOpen,
    Settings,
    ChevronDown,
    Check,
    Plus,
    HelpCircle,
    MoreHorizontal,
    CreditCard,
    ExternalLink,
    Moon,
    Sun,
    Sparkles,
    Search,
    LogOut,
    Shield,
    Send,
    MailX,
    Archive,
    FilePenLine,
    PenLine,
    Trash2,
} from '@lucide/vue';

const page = usePage();
const moreSheetOpen = ref(false);
const teamOpen = ref(false);
const accountOpen = ref(false);
const showCreateTeam = ref(false);
const newTeamName = ref('');
const newTeamSubdomain = ref('');
const createTeamErrors = ref({});
const createTeamFields = ref(null);
const baseDomain = computed(() => page.props.tenant?.base_domain || '');
const user = computed(() => page.props.auth?.user);
const accessState = computed(() => page.props.access || {});
const { open: openPlans } = usePlansModal();

const { open: openCompose } = useComposeModal();
const { open: openOnboarding } = useOnboarding();
const { open: openCommandPalette } = useCommandPalette();
const { inboxUnread } = useNotifications();
const { liveConnected } = useInboxLive();
const { theme, setTheme } = useTheme();
const toast = useToast();
const { hideMobileHeader } = useMobileChrome();

watch(
    () => page.props.auth?.user?.preferences?.inbox_sound,
    (enabled) => setInboxSoundPreference(enabled !== false),
    { immediate: true },
);

watch(
    () => page.url,
    () => {
        moreSheetOpen.value = false;
    },
);

onMounted(() => {
    const unlock = () => {
        unlockInboxAudio();
        window.removeEventListener('pointerdown', unlock, true);
        window.removeEventListener('keydown', unlock, true);
        window.removeEventListener('touchstart', unlock, true);
    };
    window.addEventListener('pointerdown', unlock, { capture: true, once: true });
    window.addEventListener('keydown', unlock, { capture: true, once: true });
    window.addEventListener('touchstart', unlock, { capture: true, once: true });
});

const {
    workspaces,
    activeWorkspace,
    activeWorkspaceId,
    selectWorkspace: switchWorkspace,
    hostLocked,
    canSend,
} = useTenant();

const colorMap = {
    cyan: 'bg-cyan-400/20 text-cyan-300',
    violet: 'bg-violet-400/20 text-violet-300',
    emerald: 'bg-emerald-400/20 text-emerald-300',
    amber: 'bg-amber-400/20 text-amber-300',
};

const navGroups = computed(() => {
    const abilities = page.props.auth?.abilities || {};
    const groups = [
        {
            label: 'Mail',
            items: [
                { name: 'Emails', route: 'emails', icon: Mail, ability: 'manage' },
                { name: 'Inbox', route: 'inbox', icon: Inbox, ability: 'inbox' },
                { name: 'Sent', route: 'sent', icon: Send, ability: 'inbox' },
                { name: 'Drafts', route: 'drafts', icon: FilePenLine, ability: 'mail' },
                // Members keep Archive in Mail; admins use Inbox folder tabs instead.
                { name: 'Archive', route: 'archive', icon: Archive, ability: 'inbox', hideWhen: 'manage' },
                { name: 'Bounced', route: 'bounced', icon: MailX, ability: 'manage' },
                // Personal signature — mailbox members only (admins use Settings → Signature).
                { name: 'Signature', route: 'mailbox.signature', icon: PenLine, ability: 'inbox', hideWhen: 'manage' },
                { name: 'Profile', route: 'profile.edit', icon: User, hideWhen: 'manage' },
                { name: 'Groups', route: 'groups', icon: UsersRound, ability: 'manage' },
                { name: 'Users', route: 'users', icon: UserCog, ability: 'manage' },
            ],
        },
        {
            label: 'Engage',
            items: [
                { name: 'Broadcasts', route: 'broadcasts', icon: Megaphone, ability: 'marketing' },
                { name: 'Automations', route: 'automations', icon: Workflow, ability: 'marketing' },
                { name: 'Templates', route: 'templates', icon: LayoutTemplate, ability: 'marketing' },
                { name: 'Audience', route: 'audience', icon: Users, ability: 'marketing' },
            ],
        },
        {
            label: 'Deliverability',
            items: [
                { name: 'Metrics', route: 'metrics', icon: BarChart3, ability: 'manage' },
                { name: 'Domains', route: 'domains', icon: Globe, ability: 'manage' },
                { name: 'Logs', route: 'logs', icon: ScrollText, ability: 'manage' },
            ],
        },
        {
            label: 'Developers',
            items: [
                { name: 'API Keys', route: 'api-keys', icon: KeyRound, ability: 'manage' },
                { name: 'Webhooks', route: 'webhooks', icon: Webhook, ability: 'manage' },
                { name: 'Settings', route: 'settings', params: 'usage', icon: Settings, ability: 'manage' },
            ],
        },
    ];

    return groups
        .map((group) => ({
            ...group,
            items: group.items.filter(
                (item) =>
                    (!item.ability || abilities[item.ability]) &&
                    (!item.hideWhen || !abilities[item.hideWhen]),
            ),
        }))
        .filter((group) => group.items.length > 0);
});

const navHref = (item) => {
    if (!item.route) return '#';
    if (item.params !== undefined) return route(item.route, item.params);
    return route(item.route);
};

const pageTitle = computed(() => {
    const path = page.url.split('?')[0];
    for (const group of navGroups.value) {
        for (const item of group.items) {
            if (!item.route) continue;
            if (item.route === 'settings' && path.startsWith('/settings')) {
                return item.name;
            }
            try {
                const target = new URL(navHref(item), 'http://local').pathname;
                if (path === target || path.startsWith(target + '/')) {
                    return item.name;
                }
            } catch {
                /* ignore */
            }
        }
    }
    if (path.startsWith('/docs')) return 'Docs';
    if (path.startsWith('/help')) return 'Help';
    if (path.startsWith('/profile')) return 'Profile';
    if (path.startsWith('/archive')) return 'Archive';
    if (path.startsWith('/trash')) return 'Trash';
    return 'MailDesk';
});

const abilities = computed(() => page.props.auth?.abilities || {});
const canCompose = computed(() => Boolean(abilities.value.mail));
const canManage = computed(() => Boolean(abilities.value.manage));

const onNavAction = (item) => {
    moreSheetOpen.value = false;
    if (item.action === 'compose') {
        if (!canSend.value) {
            toast.error(
                'This workspace has no active mail provider. Reassign it in SaaS Admin.',
            );
            return;
        }
        if (!canCompose.value) {
            toast.error('You do not have permission to compose mail.');
            return;
        }
        openCompose();
    }
};

const moreExcludeRoutes = computed(() => {
    const routes = [];
    if (abilities.value.inbox) {
        routes.push('inbox', 'sent');
    }
    if (abilities.value.manage) {
        routes.push('emails');
    }
    if (abilities.value.mail) {
        routes.push('drafts');
    }
    if (abilities.value.marketing) {
        routes.push('audience');
    }
    return routes;
});

const signOut = () => {
    accountOpen.value = false;
    router.post(route('logout'), {
        _token: page.props.csrf_token,
    });
};

const closeTeam = () => {
    teamOpen.value = false;
};

const closeMenus = () => {
    teamOpen.value = false;
    accountOpen.value = false;
};

const onDocClick = () => closeMenus();

onMounted(() => {
    document.addEventListener('click', onDocClick);
    try {
        if (!localStorage.getItem('maildesk_onboarding_seen')) {
            localStorage.setItem('maildesk_onboarding_seen', '1');
            window.setTimeout(() => openOnboarding(), 600);
        }
    } catch {
        /* ignore */
    }
});
onUnmounted(() => document.removeEventListener('click', onDocClick));

const selectWorkspace = (ws) => {
    teamOpen.value = false;
    if (ws.id === activeWorkspaceId.value) return;
    switchWorkspace(ws.id);
    toast.success(`Switching to ${ws.name}…`);
};

const createTeam = () => {
    const name = newTeamName.value.trim();
    if (!name) return;
    router.post(
        route('workspaces.store'),
        { name, subdomain: newTeamSubdomain.value.trim() },
        {
            onSuccess: () => {
                newTeamName.value = '';
                newTeamSubdomain.value = '';
                createTeamErrors.value = {};
                createTeamFields.value?.reset();
                showCreateTeam.value = false;
                toast.success(`Created ${name}`);
            },
            onError: (errors) => {
                createTeamErrors.value = errors;
                toast.error('Could not create workspace.');
            },
        },
    );
};
</script>

<template>
    <div class="min-h-screen bg-black text-zinc-100">
        <ImpersonationBanner />
        <div
            v-if="accessState.lockout"
            class="border-b border-amber-500/40 bg-amber-500/10 px-4 py-3 text-sm text-amber-100"
            data-testid="trial-lockout-banner"
        >
            <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-3">
                <p>{{ accessState.reason || 'Your free trial has ended. Choose a plan to continue.' }}</p>
                <button type="button" class="md-btn-solid !py-1.5 !text-xs" @click="openPlans()">
                    View plans
                </button>
            </div>
        </div>
        <!-- Mobile app header -->
        <header
            v-show="!hideMobileHeader"
            class="sticky top-0 z-30 flex items-center justify-between gap-3 border-b border-zinc-800/80 bg-black/85 px-3 py-2.5 backdrop-blur-xl lg:hidden"
            style="padding-top: max(0.625rem, env(safe-area-inset-top, 0px))"
            data-testid="mobile-app-header"
        >
            <button
                v-if="activeWorkspace"
                type="button"
                class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl text-sm font-semibold transition active:scale-95"
                :class="colorMap[activeWorkspace.color] || colorMap.cyan"
                aria-label="Open menu"
                data-testid="mobile-workspace-button"
                @click="moreSheetOpen = true"
            >
                {{ activeWorkspace.name.slice(0, 1) }}
            </button>
            <div
                v-else
                class="h-11 w-11 shrink-0"
                aria-hidden="true"
            />
            <div class="min-w-0 flex-1 text-center">
                <div class="truncate text-[11px] font-medium text-zinc-500">
                    {{ activeWorkspace?.name || 'MailDesk' }}
                </div>
                <div class="truncate text-sm font-semibold tracking-tight text-white">
                    {{ pageTitle }}
                </div>
            </div>
            <div class="flex shrink-0 items-center gap-1">
                <button
                    type="button"
                    class="flex h-11 w-11 items-center justify-center rounded-xl border border-zinc-800 text-zinc-400 transition active:scale-95 hover:border-zinc-700 hover:text-zinc-200"
                    aria-label="Search"
                    @click="openCommandPalette()"
                >
                    <Search :size="18" />
                </button>
                <NotificationsMenu />
            </div>
        </header>

        <div class="lg:flex lg:items-start">
            <aside
                class="sticky top-0 z-auto hidden h-dvh w-[248px] shrink-0 flex-col border-r border-zinc-900 bg-zinc-950/95 backdrop-blur-xl lg:flex"
            >
                <!-- Workspace switcher -->
                <div v-if="activeWorkspace" class="relative border-b border-zinc-900/80 px-3 py-3">
                    <button
                        type="button"
                        class="group flex w-full items-center gap-2.5 rounded-xl px-2 py-2 text-left transition hover:bg-zinc-900"
                        @click.stop="teamOpen = !teamOpen"
                    >
                        <span
                            class="flex h-8 w-8 items-center justify-center rounded-lg text-xs font-semibold transition group-hover:scale-105"
                            :class="colorMap[activeWorkspace.color] || colorMap.cyan"
                        >
                            {{ activeWorkspace.name.slice(0, 1) }}
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="flex items-center gap-1.5">
                                <span class="truncate text-sm font-medium text-white">
                                    {{ activeWorkspace.name }}
                                </span>
                                <span
                                    class="rounded-full bg-cyan-400/15 px-1.5 py-0.5 text-[10px] font-medium text-cyan-300"
                                >
                                    {{ activeWorkspace.plan }}
                                </span>
                            </span>
                            <span class="block truncate text-[11px] text-zinc-500">
                                {{
                                    activeWorkspace.host ||
                                    activeWorkspace.email
                                }}
                            </span>
                        </span>
                        <ChevronDown
                            :size="14"
                            class="text-zinc-500 transition duration-200"
                            :class="{ 'rotate-180 text-cyan-300': teamOpen }"
                        />
                    </button>

                    <Transition
                        enter-active-class="transition duration-150 ease-out"
                        enter-from-class="-translate-y-1 opacity-0"
                        enter-to-class="translate-y-0 opacity-100"
                        leave-active-class="transition duration-100 ease-in"
                        leave-from-class="opacity-100"
                        leave-to-class="opacity-0"
                    >
                        <div
                            v-if="teamOpen"
                            class="absolute left-3 right-3 z-50 mt-1 overflow-hidden rounded-xl border border-zinc-800 bg-zinc-950 shadow-2xl shadow-black/50"
                            @click.stop
                        >
                            <div class="border-b border-zinc-900 px-3 py-2 text-[11px] font-medium uppercase tracking-wide text-zinc-500">
                                {{
                                    hostLocked
                                        ? 'Switch workspace host'
                                        : 'Workspaces'
                                }}
                            </div>
                            <div class="max-h-64 overflow-y-auto py-1">
                                <button
                                    v-for="ws in workspaces"
                                    :key="ws.id"
                                    type="button"
                                    class="flex w-full items-center gap-2.5 px-3 py-2 text-left transition hover:bg-zinc-900"
                                    @click="selectWorkspace(ws)"
                                >
                                    <span
                                        class="flex h-7 w-7 items-center justify-center rounded-md text-[11px] font-semibold"
                                        :class="colorMap[ws.color] || colorMap.cyan"
                                    >
                                        {{ ws.name.slice(0, 1) }}
                                    </span>
                                    <span class="min-w-0 flex-1">
                                        <span class="flex items-center gap-1.5">
                                            <span class="truncate text-sm text-zinc-200">{{
                                                ws.name
                                            }}</span>
                                            <span
                                                class="rounded-full bg-zinc-800 px-1.5 py-0.5 text-[10px] text-zinc-400"
                                            >
                                                {{ ws.plan }}
                                            </span>
                                            <span
                                                v-if="!ws.providerOk"
                                                class="rounded-full bg-rose-500/15 px-1.5 py-0.5 text-[10px] text-rose-300"
                                            >
                                                no provider
                                            </span>
                                        </span>
                                        <span class="block truncate text-[11px] text-zinc-600">{{
                                            ws.host || ws.email
                                        }}</span>
                                    </span>
                                    <Check
                                        v-if="ws.id === activeWorkspaceId"
                                        :size="14"
                                        class="text-cyan-400"
                                    />
                                </button>
                            </div>
                            <div class="border-t border-zinc-900 p-1">
                                <button
                                    type="button"
                                    class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-sm text-zinc-300 transition hover:bg-zinc-900 hover:text-white"
                                    @click="
                                        teamOpen = false;
                                        showCreateTeam = true;
                                    "
                                >
                                    <Plus :size="14" class="text-cyan-300" />
                                    Create account
                                </button>
                                <button
                                    type="button"
                                    class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-sm text-zinc-300 transition hover:bg-zinc-900 hover:text-white"
                                    @click="
                                        teamOpen = false;
                                        openPlans('transactional');
                                    "
                                >
                                    <CreditCard :size="14" class="text-cyan-300" />
                                    View plans
                                </button>
                            </div>
                        </div>
                    </Transition>
                </div>

                <!-- Grouped nav -->
                <nav class="min-h-0 flex-1 space-y-4 overflow-y-auto px-2 py-3 pb-[calc(5rem+env(safe-area-inset-bottom,0px))] lg:pb-3">
                    <div
                        v-for="(group, gi) in navGroups"
                        :key="group.label"
                        class="animate-slide-up"
                        :style="{ animationDelay: `${gi * 40}ms` }"
                    >
                        <div
                            class="mb-1 px-2.5 text-[10px] font-semibold uppercase tracking-[0.14em] text-zinc-600"
                        >
                            {{ group.label }}
                        </div>
                        <div class="space-y-0.5">
                            <template
                                v-for="item in group.items"
                                :key="item.route || item.action"
                            >
                                <button
                                    v-if="item.action"
                                    type="button"
                                    class="group relative flex w-full items-center gap-2.5 rounded-lg px-2.5 py-2 text-left text-[13px] text-zinc-400 transition-all duration-200 hover:bg-zinc-900 hover:text-zinc-100"
                                    @click="onNavAction(item)"
                                >
                                    <span
                                        class="flex h-4 w-4 shrink-0 items-center justify-center text-zinc-500 transition-transform duration-200 group-hover:scale-110 group-hover:text-cyan-300"
                                    >
                                        <component
                                            :is="item.icon"
                                            :size="16"
                                            :stroke-width="1.75"
                                        />
                                    </span>
                                    <span class="truncate">{{ item.name }}</span>
                                </button>
                                <SidebarLink
                                    v-else
                                    :href="navHref(item)"
                                    v-bind="
                                        item.route === 'settings'
                                            ? {
                                                  active: page.url
                                                      .split('?')[0]
                                                      .startsWith('/settings'),
                                              }
                                            : {}
                                    "
                                    :badge="
                                        item.route === 'inbox'
                                            ? inboxUnread
                                            : null
                                    "
                                >
                                    <template #icon>
                                        <component
                                            :is="item.icon"
                                            :size="16"
                                            :stroke-width="1.75"
                                            class="transition duration-200"
                                        />
                                    </template>
                                    {{ item.name }}
                                </SidebarLink>
                            </template>
                        </div>
                    </div>
                </nav>

                <div
                    v-if="abilities.inbox && !abilities.manage"
                    class="shrink-0 px-2 pb-1"
                >
                    <SidebarLink
                        :href="route('trash')"
                    >
                        <template #icon>
                            <Trash2
                                :size="16"
                                :stroke-width="1.75"
                                class="transition duration-200"
                            />
                        </template>
                        Trash
                    </SidebarLink>
                </div>

                <div class="relative shrink-0 border-t border-zinc-900 bg-zinc-950/80 p-3">
                    <Transition
                        enter-active-class="transition duration-150 ease-out"
                        enter-from-class="translate-y-1 opacity-0"
                        enter-to-class="translate-y-0 opacity-100"
                        leave-active-class="transition duration-100 ease-in"
                        leave-from-class="opacity-100"
                        leave-to-class="opacity-0"
                    >
                        <div
                            v-if="accountOpen"
                            class="absolute bottom-full left-3 right-3 z-50 mb-2 overflow-hidden rounded-xl border border-zinc-800 bg-zinc-950 shadow-2xl shadow-black/50"
                            @click.stop
                        >
                            <div class="py-1">
                                <div
                                    class="flex items-center justify-between px-3 py-2.5 text-sm text-zinc-300"
                                >
                                    <span>Appearance</span>
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
                                            title="Light"
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
                                            title="Dark"
                                            @click="setTheme('dark')"
                                        >
                                            <Moon :size="13" />
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div class="border-t border-zinc-900 py-1">
                                <Link
                                    href="/"
                                    class="flex items-center justify-between px-3 py-2.5 text-sm text-zinc-300 transition hover:bg-zinc-900 hover:text-white"
                                    @click="accountOpen = false"
                                >
                                    Homepage
                                    <ExternalLink :size="13" class="text-zinc-500" />
                                </Link>
                                <Link
                                    v-if="user?.is_platform_admin"
                                    :href="route('admin.dashboard')"
                                    class="flex w-full items-center gap-2 px-3 py-2.5 text-left text-sm text-zinc-300 transition hover:bg-zinc-900 hover:text-white"
                                    @click="accountOpen = false"
                                >
                                    <Shield :size="13" class="text-cyan-300" />
                                    SaaS Admin
                                </Link>
                                <button
                                    type="button"
                                    class="flex w-full items-center gap-2 px-3 py-2.5 text-left text-sm text-zinc-300 transition hover:bg-zinc-900 hover:text-white"
                                    @click="
                                        accountOpen = false;
                                        openOnboarding();
                                    "
                                >
                                    <Sparkles :size="13" class="text-cyan-300" />
                                    Onboarding
                                </button>
                            </div>
                            <div class="border-t border-zinc-900 py-1">
                                <Link
                                    :href="route('profile.edit')"
                                    class="flex w-full items-center gap-2 px-3 py-2.5 text-left text-sm text-zinc-300 transition hover:bg-zinc-900 hover:text-white"
                                    @click="accountOpen = false"
                                >
                                    <User :size="13" class="text-zinc-500" />
                                    Profile
                                </Link>
                                <button
                                    type="button"
                                    class="flex w-full items-center gap-2 px-3 py-2.5 text-left text-sm text-rose-400 transition hover:bg-zinc-900 hover:text-rose-300"
                                    @click="signOut"
                                >
                                    <LogOut :size="13" />
                                    Sign out
                                </button>
                            </div>
                        </div>
                    </Transition>

                    <button
                        type="button"
                        class="flex w-full items-center gap-2 rounded-xl px-2 py-2 text-left transition hover:bg-zinc-900"
                        @click.stop="
                            accountOpen = !accountOpen;
                            teamOpen = false;
                        "
                    >
                        <span
                            class="flex h-8 w-8 items-center justify-center rounded-full bg-gradient-to-br from-cyan-400/40 to-zinc-700 text-[11px] font-semibold text-white shadow-sm ring-1 ring-white/10"
                        >
                            {{ (user?.name || 'U').slice(0, 1).toUpperCase() }}
                        </span>
                        <div class="min-w-0 flex-1">
                            <div class="truncate text-xs font-medium text-zinc-200">
                                {{ user?.name || 'User' }}
                            </div>
                            <div class="truncate text-[11px] text-zinc-500">
                                {{ user?.email }}
                            </div>
                        </div>
                        <MoreHorizontal :size="16" class="text-zinc-500" />
                    </button>
                </div>
            </aside>

            <div class="flex min-h-screen min-w-0 flex-1 flex-col">
                <!-- Top bar -->
                <header
                    class="sticky top-0 z-20 hidden items-center justify-between border-b border-zinc-900/80 bg-black/80 px-6 py-3 backdrop-blur-xl lg:flex"
                >
                    <div class="animate-fade-in text-sm text-zinc-500">
                        <span class="text-zinc-600">{{
                            activeWorkspace?.name || 'Workspace'
                        }}</span>
                        <span class="mx-2 text-zinc-700">/</span>
                        <span class="font-medium text-zinc-200">{{ pageTitle }}</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span
                            v-if="liveConnected"
                            class="inline-flex items-center gap-1.5 rounded-full border border-emerald-500/30 bg-emerald-500/10 px-2.5 py-1 text-[11px] font-medium text-emerald-300"
                            title="Inbox updates over WebSocket"
                        >
                            <span
                                class="relative flex h-1.5 w-1.5"
                                aria-hidden="true"
                            >
                                <span
                                    class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-60"
                                />
                                <span
                                    class="relative inline-flex h-1.5 w-1.5 rounded-full bg-emerald-400"
                                />
                            </span>
                            Live
                        </span>
                        <button
                            type="button"
                            class="group inline-flex items-center gap-2 rounded-full border border-zinc-800 px-3 py-1.5 text-sm text-zinc-400 transition hover:border-zinc-700 hover:text-zinc-200"
                            @click="openCommandPalette()"
                        >
                            <Search :size="14" />
                            <span class="hidden xl:inline">Search</span>
                            <kbd
                                class="hidden rounded border border-zinc-800 px-1.5 py-0.5 text-[10px] text-zinc-500 sm:inline"
                                >⌘K</kbd
                            >
                        </button>
                        <NotificationsMenu />
                        <Link
                            v-if="canManage"
                            :href="route('docs')"
                            class="group inline-flex items-center gap-1.5 rounded-full border border-zinc-800 px-3 py-1.5 text-sm text-zinc-300 transition hover:border-cyan-400/40 hover:text-cyan-300"
                        >
                            <BookOpen
                                :size="14"
                                class="transition group-hover:-rotate-6 group-hover:scale-110"
                            />
                            Docs
                        </Link>
                        <Link
                            v-if="canManage"
                            :href="route('help')"
                            class="group inline-flex items-center gap-1.5 rounded-full border border-zinc-800 px-3 py-1.5 text-sm text-zinc-400 transition hover:border-zinc-700 hover:text-zinc-200"
                        >
                            <HelpCircle
                                :size="14"
                                class="transition group-hover:rotate-12"
                            />
                            Help
                        </Link>
                    </div>
                </header>

                <main class="flex-1">
                    <div
                        class="mx-auto max-w-7xl animate-fade-in px-4 pb-[calc(5.5rem+env(safe-area-inset-bottom,0px))] pt-4 sm:px-6 lg:px-8 lg:pb-24 lg:pt-6"
                    >
                        <slot />
                    </div>
                </main>
            </div>
        </div>

        <MobileTabBar
            :more-open="moreSheetOpen"
            @toggle-more="moreSheetOpen = !moreSheetOpen"
        />

        <MobileMoreSheet
            :show="moreSheetOpen"
            :groups="navGroups"
            :exclude-routes="moreExcludeRoutes"
            @close="moreSheetOpen = false"
            @create-workspace="showCreateTeam = true"
        />

        <Modal
            :show="showCreateTeam"
            title="Create account"
            description="Add another workspace for a product or brand."
            max-width="md"
            @close="showCreateTeam = false"
        >
            <CreateAccountFields
                ref="createTeamFields"
                v-model:name="newTeamName"
                v-model:subdomain="newTeamSubdomain"
                :base-domain="baseDomain"
                :errors="createTeamErrors"
                @submit="createTeam"
            />
            <template #footer>
                <button
                    type="button"
                    class="md-btn-ghost"
                    @click="showCreateTeam = false"
                >
                    Cancel
                </button>
                <button type="button" class="md-btn-primary" @click="createTeam">
                    Create account
                </button>
            </template>
        </Modal>

        <ToastContainer />
        <PlansModal />
        <ComposeModal />
        <FloatingComposeButton v-if="canCompose" />
        <CommandPalette />
        <OnboardingModal />
    </div>
</template>
