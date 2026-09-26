<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import EmptyState from '@/Components/EmptyState.vue';
import { useToast } from '@/composables/useToast';
import {
    Code2,
    Copy,
    Eye,
    EyeOff,
    MoreHorizontal,
    Pause,
    RefreshCw,
    Send,
    Trash2,
    Webhook,
} from '@lucide/vue';

const props = defineProps({
    webhook: { type: Object, required: true },
    deliveries: { type: Array, default: () => [] },
    plainWebhookSecret: { type: String, default: null },
    canManage: { type: Boolean, default: false },
});

const toast = useToast();
const showDelete = ref(false);
const menuOpen = ref(false);
const revealSecret = ref(Boolean(props.plainWebhookSecret));
const showRotate = ref(false);
const testing = ref(false);

const hook = computed(() => props.webhook);
const status = computed(() => hook.value.status || 'enabled');
const secret = computed(
    () =>
        props.plainWebhookSecret ||
        hook.value.secret ||
        hook.value.secret_masked ||
        '',
);

const listening = computed(
    () => (hook.value.events || []).join(', ') || '—',
);

onMounted(() => {
    document.addEventListener('click', closeMenu);
});
onUnmounted(() => document.removeEventListener('click', closeMenu));

const closeMenu = () => {
    menuOpen.value = false;
};

const maskedSecret = computed(() =>
    revealSecret.value ? secret.value : '•'.repeat(28),
);

const copySecret = async () => {
    try {
        await navigator.clipboard.writeText(secret.value);
        toast.success('Signing secret copied.');
    } catch {
        toast.error('Copy failed.');
    }
};

const toggleStatus = () => {
    menuOpen.value = false;
    router.put(
        route('webhooks.update', hook.value.id),
        { is_active: status.value !== 'enabled' },
        {
            preserveScroll: true,
            onSuccess: () =>
                toast.success(
                    status.value === 'enabled'
                        ? 'Endpoint disabled.'
                        : 'Endpoint enabled.',
                ),
        },
    );
};

const flashResult = (page) => {
    const flash = page?.props?.flash || {};
    if (flash.error) toast.error(flash.error, 8000);
    else if (flash.success) toast.success(flash.success);
};

const sendTest = () => {
    menuOpen.value = false;
    testing.value = true;
    router.post(
        route('webhooks.test', hook.value.id),
        {},
        {
            preserveScroll: true,
            onSuccess: flashResult,
            onFinish: () => {
                testing.value = false;
            },
        },
    );
};

const rotateSecret = () => {
    router.post(
        route('webhooks.rotate', hook.value.id),
        {},
        {
            preserveScroll: true,
            onSuccess: (page) => {
                showRotate.value = false;
                revealSecret.value = true;
                flashResult(page);
            },
            onError: () => toast.error('Could not rotate the secret.'),
        },
    );
};

const remove = () => {
    router.delete(route('webhooks.destroy', hook.value.id), {
        onSuccess: () => toast.info('Webhook deleted.'),
    });
};
</script>

<template>
    <Head title="Webhook" />

    <AppLayout>
        <div class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="flex items-start gap-4">
                <span
                    class="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-cyan-400/15 text-cyan-300"
                >
                    <Webhook :size="28" :stroke-width="1.5" />
                </span>
                <div class="min-w-0">
                    <h1 class="text-2xl font-semibold tracking-tight text-white">
                        Webhook
                    </h1>
                    <p class="mt-1 truncate font-mono text-sm text-zinc-500">
                        {{ hook.endpoint }}
                    </p>
                </div>
            </div>
            <div class="relative flex items-center gap-2">
                <button
                    type="button"
                    class="md-btn-ghost"
                    :disabled="testing"
                    @click="sendTest"
                >
                    <Send :size="14" />
                    {{ testing ? 'Sending…' : 'Send test event' }}
                </button>
                <Link
                    :href="route('docs')"
                    class="md-btn-ghost !px-2.5"
                    title="API"
                >
                    <Code2 :size="16" />
                </Link>
                <button
                    type="button"
                    class="md-btn-ghost !px-2.5"
                    @click.stop="menuOpen = !menuOpen"
                >
                    <MoreHorizontal :size="16" />
                </button>
                <div
                    v-if="menuOpen"
                    class="absolute right-0 top-full z-30 mt-1 w-48 overflow-hidden rounded-xl border border-zinc-800 bg-zinc-950 py-1 shadow-2xl"
                    @click.stop
                >
                    <button
                        type="button"
                        class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-zinc-300 hover:bg-zinc-900"
                        @click="toggleStatus"
                    >
                        <Pause :size="14" class="text-zinc-500" />
                        {{
                            status === 'enabled'
                                ? 'Disable endpoint'
                                : 'Enable endpoint'
                        }}
                    </button>
                    <button
                        v-if="canManage"
                        type="button"
                        class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-zinc-300 hover:bg-zinc-900"
                        @click="
                            menuOpen = false;
                            showRotate = true;
                        "
                    >
                        <RefreshCw :size="14" class="text-zinc-500" />
                        Rotate signing secret
                    </button>
                    <button
                        type="button"
                        class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-rose-400 hover:bg-zinc-900"
                        @click="
                            menuOpen = false;
                            showDelete = true;
                        "
                    >
                        <Trash2 :size="14" />
                        Delete webhook
                    </button>
                </div>
            </div>
        </div>

        <div
            class="mb-8 grid gap-6 border-b border-zinc-900 pb-8 sm:grid-cols-2 lg:grid-cols-4"
        >
            <div>
                <div
                    class="text-[11px] font-medium uppercase tracking-wide text-zinc-500"
                >
                    Listening for
                </div>
                <div class="mt-2 font-mono text-sm text-zinc-200">
                    {{ listening }}
                </div>
            </div>
            <div>
                <div
                    class="text-[11px] font-medium uppercase tracking-wide text-zinc-500"
                >
                    Status
                </div>
                <div class="mt-2">
                    <StatusBadge :status="status" />
                </div>
            </div>
            <div>
                <div
                    class="text-[11px] font-medium uppercase tracking-wide text-zinc-500"
                >
                    Created
                </div>
                <div class="mt-2 text-sm text-zinc-300">
                    {{ hook.created || 'just now' }}
                </div>
            </div>
            <div>
                <div
                    class="text-[11px] font-medium uppercase tracking-wide text-zinc-500"
                >
                    Signing secret
                </div>
                <div
                    class="mt-2 flex items-center gap-1.5 rounded-lg border border-zinc-800 bg-zinc-950 px-2.5 py-1.5"
                >
                    <code
                        class="min-w-0 flex-1 truncate font-mono text-xs text-zinc-300"
                        >{{ maskedSecret }}</code
                    >
                    <button
                        type="button"
                        class="shrink-0 p-1 text-zinc-500 hover:text-cyan-300"
                        title="Copy"
                        @click="copySecret"
                    >
                        <Copy :size="14" />
                    </button>
                    <button
                        type="button"
                        class="shrink-0 p-1 text-zinc-500 hover:text-cyan-300"
                        title="Reveal"
                        @click="revealSecret = !revealSecret"
                    >
                        <EyeOff v-if="revealSecret" :size="14" />
                        <Eye v-else :size="14" />
                    </button>
                </div>
            </div>
        </div>

        <div class="md-card min-h-[280px] p-6">
            <EmptyState
                v-if="!deliveries.length"
                title="No webhook events yet"
                description="Once you start sending emails, you'll be able to see all the webhook events."
            />
            <ul v-else class="divide-y divide-zinc-900">
                <li
                    v-for="delivery in deliveries"
                    :key="delivery.id"
                    class="flex items-center justify-between gap-3 py-3 text-sm"
                >
                    <div>
                        <div class="font-mono text-zinc-200">
                            {{ delivery.event }}
                        </div>
                        <div class="mt-0.5 text-xs text-zinc-500">
                            {{ delivery.delivered_at }}
                            <span v-if="delivery.response_status">
                                · HTTP {{ delivery.response_status }}
                            </span>
                            <span v-if="delivery.attempts > 1">
                                · {{ delivery.attempts }} attempts
                            </span>
                        </div>
                        <div
                            v-if="delivery.error"
                            class="mt-0.5 max-w-xl truncate text-xs text-rose-400/80"
                            :title="delivery.error"
                        >
                            {{ delivery.error }}
                        </div>
                    </div>
                    <StatusBadge :status="delivery.status" />
                </li>
            </ul>
        </div>

        <div class="mt-6">
            <Link
                :href="route('webhooks')"
                class="text-sm text-zinc-500 hover:text-cyan-300"
            >
                ← Back to webhooks
            </Link>
        </div>

        <Modal
            :show="showRotate"
            title="Rotate signing secret?"
            description="A new secret is generated and shown once. The current secret stops working immediately."
            @close="showRotate = false"
        >
            <template #footer>
                <button
                    type="button"
                    class="md-btn-ghost"
                    @click="showRotate = false"
                >
                    Cancel
                </button>
                <button
                    type="button"
                    class="md-btn-primary"
                    @click="rotateSecret"
                >
                    Rotate secret
                </button>
            </template>
        </Modal>

        <Modal
            :show="showDelete"
            title="Delete webhook?"
            description="This endpoint will stop receiving events."
            @close="showDelete = false"
        >
            <p class="truncate font-mono text-xs text-zinc-500">
                {{ hook.endpoint }}
            </p>
            <template #footer>
                <button
                    type="button"
                    class="md-btn-ghost"
                    @click="showDelete = false"
                >
                    Cancel
                </button>
                <button
                    type="button"
                    class="md-btn rounded-full bg-rose-500 px-3.5 py-2 text-sm font-medium text-white hover:bg-rose-400"
                    @click="remove"
                >
                    Delete webhook
                </button>
            </template>
        </Modal>
    </AppLayout>
</template>
