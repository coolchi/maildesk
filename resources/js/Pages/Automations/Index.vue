<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import EmptyState from '@/Components/EmptyState.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import Modal from '@/Components/Modal.vue';
import { useToast } from '@/composables/useToast';
import {
    Code2,
    Copy,
    MoreHorizontal,
    Pencil,
    Plus,
    Search,
    Trash2,
    Workflow,
    Zap,
} from '@lucide/vue';

const props = defineProps({
    automations: { type: Array, default: () => [] },
    events: { type: Array, default: () => [] },
});

const toast = useToast();
const tab = ref('automations');
const search = ref('');
const statusFilter = ref('all');
const eventSearch = ref('');

const automations = ref([]);
const events = ref([]);
watch(
    () => [props.automations, props.events],
    () => {
        automations.value = (props.automations || []).map((a) => ({ ...a }));
        events.value = (props.events || []).map((e) => ({ ...e }));
    },
    { immediate: true },
);

const menuOpen = ref(null);
const eventMenuOpen = ref(null);
const deleteTarget = ref(null);
const deleteConfirm = ref('');
const showAddEvent = ref(false);
const showCode = ref(false);
const newEventName = ref('');
const newEventDesc = ref('');

const filteredAutomations = computed(() =>
    automations.value.filter((a) => {
        const q = search.value.trim().toLowerCase();
        const searchOk = !q || a.name.toLowerCase().includes(q);
        const statusOk =
            statusFilter.value === 'all' || a.status === statusFilter.value;
        return searchOk && statusOk;
    }),
);

const filteredEvents = computed(() =>
    events.value.filter((e) => {
        const q = eventSearch.value.trim().toLowerCase();
        return (
            !q ||
            e.name.toLowerCase().includes(q) ||
            (e.description || '').toLowerCase().includes(q)
        );
    }),
);

const canDelete = computed(
    () =>
        deleteTarget.value &&
        deleteConfirm.value === deleteTarget.value.name,
);

const closeMenus = () => {
    menuOpen.value = null;
    eventMenuOpen.value = null;
};

const onDocClick = () => closeMenus();

onMounted(() => document.addEventListener('click', onDocClick));
onUnmounted(() => document.removeEventListener('click', onDocClick));

const toggleMenu = async (id, e) => {
    e.stopPropagation();
    menuOpen.value = menuOpen.value === id ? null : id;
    eventMenuOpen.value = null;
};

const toggleEventMenu = async (id, e) => {
    e.stopPropagation();
    eventMenuOpen.value = eventMenuOpen.value === id ? null : id;
    menuOpen.value = null;
};

const openDelete = (item) => {
    deleteTarget.value = item;
    deleteConfirm.value = '';
    closeMenus();
};

const confirmDelete = () => {
    if (!canDelete.value) return;
    router.delete(route('automations.destroy', deleteTarget.value.id), {
        onSuccess: () => {
            toast.success(`Deleted “${deleteTarget.value.name}”`);
            deleteTarget.value = null;
            deleteConfirm.value = '';
        },
    });
};

const onDeleteKey = (e) => {
    if (
        deleteTarget.value &&
        (e.metaKey || e.ctrlKey) &&
        e.key === 'Enter' &&
        canDelete.value
    ) {
        e.preventDefault();
        confirmDelete();
    }
};

onMounted(() => window.addEventListener('keydown', onDeleteKey));
onUnmounted(() => window.removeEventListener('keydown', onDeleteKey));

const duplicate = (item) => {
    const copy = {
        ...item,
        id: Date.now(),
        name: `${item.name} (Copy)`,
        status: 'disabled',
        runs: 0,
        created: 'just now',
        steps: [...(item.steps || [])],
    };
    automations.value.unshift(copy);
    toast.success('Automation duplicated');
    closeMenus();
};

const toggleStatus = (item) => {
    const next = item.status === 'enabled' ? 'disabled' : 'enabled';
    router.put(
        route('automations.update', item.id),
        { status: next },
        {
            preserveScroll: true,
            onSuccess: () =>
                toast.success(
                    next === 'enabled'
                        ? 'Automation enabled'
                        : 'Automation disabled',
                ),
        },
    );
    closeMenus();
};

const addEvent = () => {
    const name = newEventName.value.trim();
    if (!name) return;
    events.value.unshift({
        id: Date.now(),
        name,
        description: newEventDesc.value.trim() || 'Custom event',
        created: 'just now',
    });
    newEventName.value = '';
    newEventDesc.value = '';
    showAddEvent.value = false;
    toast.success('Event created');
};

const deleteEvent = (item) => {
    events.value = events.value.filter((e) => e.id !== item.id);
    toast.success(`Deleted event “${item.name}”`);
    closeMenus();
};

const copyName = async (name) => {
    try {
        await navigator.clipboard.writeText(name);
        toast.info('Copied');
    } catch {
        /* ignore */
    }
};

const createAutomation = () => {
    router.visit(route('automations.create'));
};

const codeSnippet = `await maildesk.events.send({
  name: 'user.created',
  payload: {
    email: 'maya@studio.co',
    user_id: 'usr_123',
  },
});`;
</script>

<template>
    <Head title="Automations" />

    <AppLayout>
        <PageHeader title="Automations" />

        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="inline-flex rounded-full border border-zinc-800 bg-zinc-950 p-1">
                <button
                    type="button"
                    class="rounded-full px-4 py-1.5 text-sm transition"
                    :class="
                        tab === 'automations'
                            ? 'bg-white text-zinc-950'
                            : 'text-zinc-400 hover:text-white'
                    "
                    @click="tab = 'automations'"
                >
                    Automations
                </button>
                <button
                    type="button"
                    class="rounded-full px-4 py-1.5 text-sm transition"
                    :class="
                        tab === 'events'
                            ? 'bg-white text-zinc-950'
                            : 'text-zinc-400 hover:text-white'
                    "
                    @click="tab = 'events'"
                >
                    Events
                </button>
            </div>

            <div class="flex items-center gap-2">
                <button
                    v-if="tab === 'automations'"
                    type="button"
                    class="md-btn-solid"
                    @click="createAutomation"
                >
                    <Plus :size="16" />
                    Create automation
                </button>
                <button
                    v-else
                    type="button"
                    class="md-btn-solid"
                    @click="showAddEvent = true"
                >
                    <Plus :size="16" />
                    Add event
                </button>
                <button
                    type="button"
                    class="md-btn-ghost"
                    title="API"
                    @click="showCode = true"
                >
                    <Code2 :size="16" />
                </button>
            </div>
        </div>

        <!-- Automations tab -->
        <div v-if="tab === 'automations'">
            <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center">
                <div class="relative grow">
                    <Search
                        :size="16"
                        class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-zinc-500"
                    />
                    <input
                        v-model="search"
                        type="search"
                        class="md-input pl-9"
                        placeholder="Search…"
                    />
                </div>
                <select v-model="statusFilter" class="md-input sm:w-44">
                    <option value="all">All statuses</option>
                    <option value="enabled">Enabled</option>
                    <option value="disabled">Disabled</option>
                </select>
            </div>

            <EmptyState
                v-if="!automations.length"
                title="No automations yet"
                description="Create an automation that sends emails when events fire."
            >
                <template #icon>
                    <Workflow :size="28" :stroke-width="1.5" />
                </template>
                <template #actions>
                    <button
                        type="button"
                        class="md-btn-solid"
                        @click="createAutomation"
                    >
                        <Plus :size="16" />
                        Create automation
                    </button>
                </template>
            </EmptyState>

            <div v-else class="md-table-wrap">
                <table class="min-w-full text-left text-sm">
                    <thead
                        class="border-b border-zinc-800 text-xs uppercase tracking-wide text-zinc-500"
                    >
                        <tr>
                            <th class="px-4 py-3 font-medium">Name</th>
                            <th class="px-4 py-3 font-medium">Status</th>
                            <th class="px-4 py-3 font-medium">Runs</th>
                            <th class="px-4 py-3 font-medium">Created</th>
                            <th class="px-4 py-3" />
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-900">
                        <tr
                            v-for="item in filteredAutomations"
                            :key="item.id"
                            class="group hover:bg-white/[0.03]"
                        >
                            <td class="px-4 py-3">
                                <Link
                                    :href="route('automations.show', item.id)"
                                    class="inline-flex items-center gap-2 font-medium text-zinc-100 hover:text-cyan-300"
                                >
                                    <span
                                        class="flex h-7 w-7 items-center justify-center rounded-md border border-zinc-800 text-cyan-300"
                                    >
                                        <Workflow :size="14" />
                                    </span>
                                    {{ item.name }}
                                </Link>
                            </td>
                            <td class="px-4 py-3">
                                <StatusBadge :status="item.status" />
                            </td>
                            <td class="px-4 py-3 tabular-nums text-zinc-400">
                                {{ item.runs }}
                            </td>
                            <td class="px-4 py-3 text-zinc-500">
                                {{ item.created }}
                            </td>
                            <td class="relative px-4 py-3 text-right">
                                <button
                                    type="button"
                                    class="rounded-md p-1.5 text-zinc-500 hover:bg-zinc-900 hover:text-white"
                                    @click="toggleMenu(item.id, $event)"
                                >
                                    <MoreHorizontal :size="16" />
                                </button>
                                <div
                                    v-if="menuOpen === item.id"
                                    class="absolute right-4 z-20 mt-1 w-44 overflow-hidden rounded-xl border border-zinc-800 bg-zinc-950 py-1 shadow-xl"
                                    @click.stop
                                >
                                    <Link
                                        :href="
                                            route('automations.show', item.id)
                                        "
                                        class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-zinc-300 hover:bg-zinc-900"
                                    >
                                        <Pencil :size="14" />
                                        Edit
                                    </Link>
                                    <button
                                        type="button"
                                        class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-zinc-300 hover:bg-zinc-900"
                                        @click="toggleStatus(item)"
                                    >
                                        <Zap :size="14" />
                                        {{
                                            item.status === 'enabled'
                                                ? 'Disable'
                                                : 'Enable'
                                        }}
                                    </button>
                                    <button
                                        type="button"
                                        class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-zinc-300 hover:bg-zinc-900"
                                        @click="duplicate(item)"
                                    >
                                        <Copy :size="14" />
                                        Duplicate
                                    </button>
                                    <button
                                        type="button"
                                        class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-rose-400 hover:bg-zinc-900"
                                        @click="openDelete(item)"
                                    >
                                        <Trash2 :size="14" />
                                        Delete
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!filteredAutomations.length">
                            <td
                                colspan="5"
                                class="px-4 py-10 text-center text-zinc-500"
                            >
                                No automations match your filters.
                            </td>
                        </tr>
                    </tbody>
                </table>
                <div
                    class="border-t border-zinc-800 px-4 py-3 text-xs text-zinc-500"
                >
                    Page 1 — {{ filteredAutomations.length }} of
                    {{ automations.length }} automations — 40 items
                </div>
            </div>
        </div>

        <!-- Events tab -->
        <div v-else>
            <div class="mb-4">
                <div class="relative">
                    <Search
                        :size="16"
                        class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-zinc-500"
                    />
                    <input
                        v-model="eventSearch"
                        type="search"
                        class="md-input pl-9"
                        placeholder="Search events…"
                    />
                </div>
            </div>

            <EmptyState
                v-if="!events.length"
                title="No events yet"
                description="Create events to trigger automations."
            >
                <template #icon>
                    <Zap :size="28" :stroke-width="1.5" />
                </template>
                <template #actions>
                    <button
                        type="button"
                        class="md-btn-solid"
                        @click="showAddEvent = true"
                    >
                        <Plus :size="16" />
                        Add event
                    </button>
                </template>
            </EmptyState>

            <div v-else class="md-table-wrap">
                <table class="min-w-full text-left text-sm">
                    <thead
                        class="border-b border-zinc-800 text-xs uppercase tracking-wide text-zinc-500"
                    >
                        <tr>
                            <th class="px-4 py-3 font-medium">Name</th>
                            <th class="px-4 py-3 font-medium">Created</th>
                            <th class="px-4 py-3" />
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-900">
                        <tr
                            v-for="event in filteredEvents"
                            :key="event.id"
                            class="hover:bg-white/[0.03]"
                        >
                            <td class="px-4 py-3">
                                <div class="font-mono text-sm text-cyan-300">
                                    {{ event.name }}
                                </div>
                                <div class="mt-0.5 text-xs text-zinc-500">
                                    {{ event.description }}
                                </div>
                            </td>
                            <td class="px-4 py-3 text-zinc-500">
                                {{ event.created }}
                            </td>
                            <td class="relative px-4 py-3 text-right">
                                <button
                                    type="button"
                                    class="rounded-md p-1.5 text-zinc-500 hover:bg-zinc-900 hover:text-white"
                                    @click="toggleEventMenu(event.id, $event)"
                                >
                                    <MoreHorizontal :size="16" />
                                </button>
                                <div
                                    v-if="eventMenuOpen === event.id"
                                    class="absolute right-4 z-20 mt-1 w-40 overflow-hidden rounded-xl border border-zinc-800 bg-zinc-950 py-1 shadow-xl"
                                    @click.stop
                                >
                                    <button
                                        type="button"
                                        class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-zinc-300 hover:bg-zinc-900"
                                        @click="
                                            newEventName = event.name;
                                            newEventDesc = event.description;
                                            showAddEvent = true;
                                            closeMenus();
                                        "
                                    >
                                        <Pencil :size="14" />
                                        Edit event
                                    </button>
                                    <button
                                        type="button"
                                        class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-rose-400 hover:bg-zinc-900"
                                        @click="deleteEvent(event)"
                                    >
                                        <Trash2 :size="14" />
                                        Delete event
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <div
                    class="border-t border-zinc-800 px-4 py-3 text-xs text-zinc-500"
                >
                    Page 1 — {{ filteredEvents.length }} of
                    {{ events.length }} events — 40 items
                </div>
            </div>
        </div>

        <!-- Delete automation modal -->
        <Modal
            :show="!!deleteTarget"
            title="Delete automation"
            max-width="md"
            @close="deleteTarget = null"
        >
            <p class="text-sm text-zinc-300">
                Are you sure you want to delete this automation?
            </p>
            <p class="mt-2 text-sm font-medium text-rose-400">
                This can not be undone.
            </p>
            <p class="mt-4 flex items-center gap-2 text-sm text-zinc-400">
                Type
                <code class="rounded bg-zinc-900 px-1.5 py-0.5 text-zinc-200">{{
                    deleteTarget?.name
                }}</code>
                to confirm.
                <button
                    type="button"
                    class="text-zinc-500 hover:text-cyan-300"
                    title="Copy"
                    @click="copyName(deleteTarget?.name)"
                >
                    <Copy :size="14" />
                </button>
            </p>
            <input
                v-model="deleteConfirm"
                type="text"
                class="md-input mt-3"
                :placeholder="deleteTarget?.name"
                autocomplete="off"
            />
            <template #footer>
                <button
                    type="button"
                    class="md-btn-ghost"
                    @click="deleteTarget = null"
                >
                    Cancel
                    <span class="text-[10px] text-zinc-500">Esc</span>
                </button>
                <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-full bg-rose-500 px-3.5 py-2 text-sm font-medium text-white transition hover:bg-rose-400 disabled:opacity-40"
                    :disabled="!canDelete"
                    @click="confirmDelete"
                >
                    <Trash2 :size="14" />
                    Delete automation
                    <span class="text-[10px] opacity-70">⌘↵</span>
                </button>
            </template>
        </Modal>

        <!-- Add event modal -->
        <Modal
            :show="showAddEvent"
            title="Add event"
            description="Events trigger matching automations when you send them via API."
            max-width="md"
            @close="showAddEvent = false"
        >
            <div class="space-y-3">
                <div>
                    <label class="mb-1.5 block text-xs text-zinc-500"
                        >Event name</label
                    >
                    <input
                        v-model="newEventName"
                        class="md-input font-mono"
                        placeholder="user.created"
                    />
                </div>
                <div>
                    <label class="mb-1.5 block text-xs text-zinc-500"
                        >Description</label
                    >
                    <input
                        v-model="newEventDesc"
                        class="md-input"
                        placeholder="Optional"
                    />
                </div>
            </div>
            <template #footer>
                <button
                    type="button"
                    class="md-btn-ghost"
                    @click="showAddEvent = false"
                >
                    Cancel
                </button>
                <button type="button" class="md-btn-primary" @click="addEvent">
                    Add event
                </button>
            </template>
        </Modal>

        <!-- Code modal -->
        <Modal
            :show="showCode"
            title="Trigger an event"
            description="Send events from your app to run automations."
            max-width="lg"
            @close="showCode = false"
        >
            <pre
                class="overflow-x-auto rounded-xl border border-zinc-800 bg-black p-4 text-[12px] leading-relaxed text-zinc-300"
            >{{ codeSnippet }}</pre>
            <template #footer>
                <button
                    type="button"
                    class="md-btn-ghost"
                    @click="showCode = false"
                >
                    Close
                </button>
                <button
                    type="button"
                    class="md-btn-primary"
                    @click="copyName(codeSnippet)"
                >
                    <Copy :size="14" />
                    Copy
                </button>
            </template>
        </Modal>
    </AppLayout>
</template>
