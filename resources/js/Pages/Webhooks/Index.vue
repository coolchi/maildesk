<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Modal from '@/Components/Modal.vue';
import EmptyState from '@/Components/EmptyState.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { useToast } from '@/composables/useToast';
import {
    Code2,
    Copy,
    MoreHorizontal,
    Pause,
    Plus,
    Trash2,
    Webhook,
} from '@lucide/vue';

const props = defineProps({
    webhooks: { type: Array, default: () => [] },
    eventOptions: {
        type: Array,
        default: () => [
            'email.sent',
            'email.delivered',
            'email.bounced',
            'email.complained',
            'email.received',
            'email.opened',
            'email.clicked',
        ],
    },
});

const toast = useToast();
const hooks = ref(props.webhooks.map((h) => ({ ...h })));
const showAdd = ref(false);
const showDelete = ref(false);
const menuId = ref(null);
const deleteTarget = ref(null);
const form = ref({
    url: '',
    events: ['email.delivered', 'email.bounced'],
});

const eventOptions = computed(() => props.eventOptions);
const perPage = ref(40);
const page = ref(1);

watch(
    () => props.webhooks,
    (value) => {
        hooks.value = value.map((h) => ({ ...h }));
    },
);

const paginated = computed(() => {
    const start = (page.value - 1) * perPage.value;
    return hooks.value.slice(start, start + perPage.value);
});

const totalPages = computed(() =>
    Math.max(1, Math.ceil(hooks.value.length / perPage.value)),
);

onMounted(() => document.addEventListener('click', closeMenu));
onUnmounted(() => document.removeEventListener('click', closeMenu));

const closeMenu = () => {
    menuId.value = null;
};

const toggleEvent = (event) => {
    if (form.value.events.includes(event)) {
        form.value.events = form.value.events.filter((e) => e !== event);
    } else {
        form.value.events.push(event);
    }
};

const addWebhook = () => {
    if (!form.value.url.startsWith('http')) {
        toast.error('Enter a valid HTTPS endpoint URL.');
        return;
    }
    if (!form.value.events.length) {
        toast.error('Select at least one event.');
        return;
    }
    router.post(
        route('webhooks.store'),
        {
            url: form.value.url,
            events: form.value.events,
        },
        {
            onSuccess: () => {
                showAdd.value = false;
                form.value = {
                    url: '',
                    events: ['email.delivered', 'email.bounced'],
                };
                toast.success('Webhook endpoint added.');
            },
            onError: (errors) => {
                toast.error(errors.url || errors.events || 'Could not add webhook.');
            },
        },
    );
};

const toggleStatus = (hook) => {
    menuId.value = null;
    router.put(
        route('webhooks.update', hook.id),
        { is_active: hook.status !== 'enabled' },
        {
            preserveScroll: true,
            onSuccess: () =>
                toast.success(
                    hook.status === 'enabled'
                        ? 'Endpoint disabled.'
                        : 'Endpoint enabled.',
                ),
        },
    );
};

const duplicate = (hook) => {
    menuId.value = null;
    router.post(
        route('webhooks.store'),
        {
            url:
                hook.endpoint +
                (hook.endpoint.includes('?') ? '&' : '?') +
                'copy=1',
            events: hook.events,
        },
        {
            onSuccess: () => toast.success('Webhook duplicated.'),
        },
    );
};

const askDelete = (hook) => {
    deleteTarget.value = hook;
    menuId.value = null;
    showDelete.value = true;
};

const confirmDelete = () => {
    if (!deleteTarget.value) return;
    router.delete(route('webhooks.destroy', deleteTarget.value.id), {
        onSuccess: () => {
            showDelete.value = false;
            deleteTarget.value = null;
            toast.info('Webhook deleted.');
        },
    });
};
</script>

<template>
    <Head title="Webhooks" />

    <AppLayout>
        <PageHeader title="Webhooks">
            <template #actions>
                <button
                    type="button"
                    class="md-btn-solid"
                    @click="showAdd = true"
                >
                    <Plus :size="16" />
                    Add webhook
                </button>
                <Link
                    :href="route('docs')"
                    class="md-btn-ghost !px-2.5"
                    title="API"
                >
                    <Code2 :size="16" />
                </Link>
            </template>
        </PageHeader>

        <EmptyState
            v-if="!hooks.length"
            title="No webhooks yet"
            description="Configure a webhook to receive real-time updates about email events."
        >
            <template #icon>
                <Webhook :size="28" :stroke-width="1.5" />
            </template>
            <template #actions>
                <button
                    type="button"
                    class="md-btn-solid"
                    @click="showAdd = true"
                >
                    <Plus :size="16" />
                    Add webhook
                </button>
            </template>
        </EmptyState>

        <template v-else>
            <div class="md-table-wrap">
                <table class="min-w-full text-left text-sm">
                    <thead
                        class="border-b border-zinc-800 text-xs text-zinc-500"
                    >
                        <tr>
                            <th class="px-4 py-3 font-medium">Endpoint</th>
                            <th class="px-4 py-3 font-medium">Status</th>
                            <th class="px-4 py-3 font-medium">Created</th>
                            <th class="w-12 px-4 py-3" />
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-900">
                        <tr
                            v-for="hook in paginated"
                            :key="hook.id"
                            class="group hover:bg-white/[0.03]"
                        >
                            <td class="px-4 py-3.5">
                                <Link
                                    :href="route('webhooks.show', hook.id)"
                                    class="inline-flex max-w-xl items-center gap-2.5 text-zinc-200 hover:text-white"
                                >
                                    <span
                                        class="flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-cyan-400/15 text-cyan-300"
                                    >
                                        <Webhook :size="14" />
                                    </span>
                                    <span class="truncate font-mono text-xs sm:text-sm">{{
                                        hook.endpoint
                                    }}</span>
                                </Link>
                            </td>
                            <td class="px-4 py-3.5">
                                <StatusBadge :status="hook.status" />
                            </td>
                            <td class="px-4 py-3.5 text-zinc-500">
                                {{ hook.created }}
                            </td>
                            <td class="relative px-4 py-3.5 text-right">
                                <button
                                    type="button"
                                    class="rounded-md p-1.5 text-zinc-500 transition hover:bg-zinc-800 hover:text-zinc-200"
                                    @click.stop="
                                        menuId =
                                            menuId === hook.id ? null : hook.id
                                    "
                                >
                                    <MoreHorizontal :size="16" />
                                </button>
                                <div
                                    v-if="menuId === hook.id"
                                    class="absolute right-4 z-30 mt-1 w-48 overflow-hidden rounded-xl border border-zinc-800 bg-zinc-950 py-1 shadow-2xl shadow-black/60"
                                    @click.stop
                                >
                                    <button
                                        type="button"
                                        class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-zinc-300 hover:bg-zinc-900"
                                        @click="toggleStatus(hook)"
                                    >
                                        <Pause :size="14" class="text-zinc-500" />
                                        {{
                                            hook.status === 'enabled'
                                                ? 'Disable endpoint'
                                                : 'Enable endpoint'
                                        }}
                                    </button>
                                    <button
                                        type="button"
                                        class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-zinc-300 hover:bg-zinc-900"
                                        @click="duplicate(hook)"
                                    >
                                        <Copy :size="14" class="text-zinc-500" />
                                        Duplicate webhook
                                    </button>
                                    <button
                                        type="button"
                                        class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-rose-400 hover:bg-zinc-900"
                                        @click="askDelete(hook)"
                                    >
                                        <Trash2 :size="14" />
                                        Delete webhook
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div
                class="mt-3 flex items-center justify-between text-xs text-zinc-500"
            >
                <span>
                    Page {{ page }} · {{ hooks.length }} of
                    {{ hooks.length }} webhooks ·
                    <select
                        v-model.number="perPage"
                        class="ml-1 rounded border-0 bg-transparent text-zinc-400 outline-none"
                    >
                        <option :value="10">10 items</option>
                        <option :value="40">40 items</option>
                        <option :value="100">100 items</option>
                    </select>
                </span>
                <span v-if="totalPages > 1" class="flex gap-2">
                    <button
                        type="button"
                        class="hover:text-zinc-300"
                        :disabled="page <= 1"
                        @click="page--"
                    >
                        Prev
                    </button>
                    <button
                        type="button"
                        class="hover:text-zinc-300"
                        :disabled="page >= totalPages"
                        @click="page++"
                    >
                        Next
                    </button>
                </span>
            </div>
        </template>

        <Modal
            :show="showAdd"
            title="Add webhook"
            description="We'll POST signed JSON events to your endpoint."
            max-width="lg"
            @close="showAdd = false"
        >
            <div class="space-y-4">
                <div>
                    <label class="mb-1.5 block text-xs text-zinc-500"
                        >Endpoint URL</label
                    >
                    <input
                        v-model="form.url"
                        class="md-input"
                        placeholder="https://api.acme.com/hooks/mail"
                    />
                </div>
                <div>
                    <div class="mb-2 text-xs text-zinc-500">Events</div>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="event in eventOptions"
                            :key="event"
                            type="button"
                            class="rounded-full border px-3 py-1 text-xs transition"
                            :class="
                                form.events.includes(event)
                                    ? 'border-cyan-400/50 bg-cyan-400/10 text-cyan-300'
                                    : 'border-zinc-800 text-zinc-400 hover:border-zinc-600'
                            "
                            @click="toggleEvent(event)"
                        >
                            {{ event }}
                        </button>
                    </div>
                </div>
            </div>
            <template #footer>
                <button
                    type="button"
                    class="md-btn-ghost"
                    @click="showAdd = false"
                >
                    Cancel
                    <kbd class="ml-1 text-[10px] text-zinc-600">Esc</kbd>
                </button>
                <button
                    type="button"
                    class="md-btn-primary"
                    @click="addWebhook"
                >
                    Add endpoint
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
                {{ deleteTarget?.endpoint }}
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
                    @click="confirmDelete"
                >
                    Delete webhook
                </button>
            </template>
        </Modal>
    </AppLayout>
</template>
