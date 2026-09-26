<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import {
    FilePenLine,
    Inbox,
    Mail,
    MoreHorizontal,
    PenSquare,
    Send,
    Users,
} from '@lucide/vue';
import { useComposeModal } from '@/composables/useComposeModal';
import { useNotifications } from '@/composables/useNotifications';
import { useTenant } from '@/composables/useTenant';
import { useToast } from '@/composables/useToast';

const props = defineProps({
    moreOpen: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['toggle-more']);

const page = usePage();
const { open, state } = useComposeModal();
const { canSend } = useTenant();
const toast = useToast();
const { inboxUnread } = useNotifications();

const abilities = computed(() => page.props.auth?.abilities || {});
const path = computed(() => page.url.split('?')[0]);

const isRouteActive = (routeName) => {
    try {
        const target = new URL(route(routeName), 'http://local').pathname;
        return path.value === target || path.value.startsWith(`${target}/`);
    } catch {
        return false;
    }
};

/**
 * Prefer a full native-style tab strip. Mail-only users get Sent/Drafts
 * instead of Emails/Audience they cannot open.
 */
const tabs = computed(() => {
    const canInbox = Boolean(abilities.value.inbox);
    const canManage = Boolean(abilities.value.manage);
    const canMarketing = Boolean(abilities.value.marketing);
    const canMail = Boolean(abilities.value.mail);

    const items = [];

    if (canInbox) {
        items.push({
            key: 'inbox',
            label: 'Inbox',
            icon: Inbox,
            href: route('inbox'),
            active: isRouteActive('inbox'),
            badge: Number(inboxUnread.value) || 0,
        });
    }

    if (canManage) {
        items.push({
            key: 'emails',
            label: 'Emails',
            icon: Mail,
            href: route('emails'),
            active: isRouteActive('emails'),
        });
    } else if (canInbox) {
        items.push({
            key: 'sent',
            label: 'Sent',
            icon: Send,
            href: route('sent'),
            active: isRouteActive('sent'),
        });
    }

    if (canMail) {
        items.push({
            key: 'compose',
            label: 'Compose',
            icon: PenSquare,
            action: 'compose',
            elevated: true,
            active: false,
        });
    }

    if (canMarketing) {
        items.push({
            key: 'audience',
            label: 'Audience',
            icon: Users,
            href: route('audience'),
            active: isRouteActive('audience'),
        });
    } else if (canInbox) {
        items.push({
            key: 'drafts',
            label: 'Drafts',
            icon: FilePenLine,
            href: route('drafts'),
            active: isRouteActive('drafts'),
        });
    }

    const anyPrimaryActive = items.some((tab) => tab.href && tab.active);

    items.push({
        key: 'more',
        label: 'More',
        icon: MoreHorizontal,
        action: 'more',
        active: props.moreOpen || !anyPrimaryActive,
    });

    return items;
});

const onCompose = () => {
    if (!canSend.value) {
        toast.error(
            'This workspace has no active mail provider. Reassign it in SaaS Admin.',
        );
        return;
    }
    if (state.open) {
        state.minimized = false;
        return;
    }
    open();
};

const onTabAction = (tab) => {
    if (tab.action === 'compose') {
        onCompose();
        return;
    }
    if (tab.action === 'more') {
        emit('toggle-more');
    }
};
</script>

<template>
    <nav
        class="fixed inset-x-0 bottom-0 z-[45] border-t border-zinc-800/80 bg-zinc-950/95 backdrop-blur-xl lg:hidden"
        style="padding-bottom: env(safe-area-inset-bottom, 0px)"
        aria-label="Primary"
        data-testid="mobile-tab-bar"
    >
        <div class="mx-auto flex h-16 max-w-lg items-stretch justify-around px-0.5">
            <template v-for="tab in tabs" :key="tab.key">
                <Link
                    v-if="tab.href"
                    :href="tab.href"
                    class="relative flex min-w-0 flex-1 flex-col items-center justify-center gap-1 px-0.5 text-[10px] font-medium transition"
                    :class="
                        tab.active
                            ? 'text-cyan-300'
                            : 'text-zinc-500 active:text-zinc-200'
                    "
                    :data-testid="`mobile-tab-${tab.key}`"
                    :aria-current="tab.active ? 'page' : undefined"
                >
                    <span class="relative inline-flex h-7 items-center justify-center">
                        <component
                            :is="tab.icon"
                            :size="22"
                            :stroke-width="tab.active ? 2.35 : 1.75"
                        />
                        <span
                            v-if="tab.badge > 0"
                            class="absolute -right-2.5 -top-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-cyan-400 px-1 text-[9px] font-semibold leading-none text-zinc-950"
                        >
                            {{ tab.badge > 99 ? '99+' : tab.badge }}
                        </span>
                    </span>
                    <span class="truncate leading-none">{{ tab.label }}</span>
                </Link>

                <button
                    v-else
                    type="button"
                    class="relative flex min-w-0 flex-1 flex-col items-center justify-center gap-1 px-0.5 text-[10px] font-medium transition"
                    :class="
                        tab.elevated
                            ? 'text-zinc-400'
                            : tab.active
                              ? 'text-cyan-300'
                              : 'text-zinc-500 active:text-zinc-200'
                    "
                    :data-testid="`mobile-tab-${tab.key}`"
                    :aria-pressed="tab.action === 'more' ? moreOpen : undefined"
                    :aria-label="tab.label"
                    @click="onTabAction(tab)"
                >
                    <span
                        v-if="tab.elevated"
                        class="md-btn-primary -mt-6 flex h-14 w-14 items-center justify-center rounded-2xl p-0 shadow-lg shadow-cyan-400/25 ring-4 ring-black"
                        :class="{ 'opacity-60': !canSend }"
                    >
                        <component :is="tab.icon" :size="24" :stroke-width="2.25" />
                    </span>
                    <template v-else>
                        <span class="inline-flex h-7 items-center justify-center">
                            <component
                                :is="tab.icon"
                                :size="22"
                                :stroke-width="tab.active ? 2.35 : 1.75"
                            />
                        </span>
                        <span class="truncate leading-none">{{ tab.label }}</span>
                    </template>
                    <span v-if="tab.elevated" class="truncate leading-none">
                        {{ tab.label }}
                    </span>
                </button>
            </template>
        </div>
    </nav>
</template>
