<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import EmptyState from '@/Components/EmptyState.vue';
import RowActions from '@/Components/RowActions.vue';
import Modal from '@/Components/Modal.vue';
import { useToast } from '@/composables/useToast';
import {
    Copy,
    Eye,
    Megaphone,
    Pause,
    Pencil,
    Plus,
    Search,
    Trash2,
} from '@lucide/vue';

const props = defineProps({
    broadcasts: { type: Array, default: () => [] },
});

const toast = useToast();
const search = ref('');
const status = ref('all');
const rows = ref([]);
watch(
    () => props.broadcasts,
    (list) => {
        rows.value = (list || []).map((b) => ({ ...b }));
    },
    { immediate: true },
);
const showDelete = ref(false);
const deleteTarget = ref(null);

const filtered = computed(() => {
    const q = search.value.trim().toLowerCase();
    return rows.value.filter((b) => {
        const statusOk = status.value === 'all' || b.status === status.value;
        const searchOk =
            !q ||
            b.name.toLowerCase().includes(q) ||
            b.audience.toLowerCase().includes(q);
        return statusOk && searchOk;
    });
});

const goCreate = () => router.visit(route('broadcasts.create'));

const goShow = (b) => router.visit(route('broadcasts.show', b.id));

const actionsFor = (b) => {
    const items = [
        { id: 'view', label: 'View', icon: Eye },
        { id: 'edit', label: 'Edit', icon: Pencil },
        { id: 'duplicate', label: 'Duplicate', icon: Copy },
    ];
    if (b.status === 'scheduled') {
        items.push({ id: 'pause', label: 'Cancel schedule', icon: Pause });
    }
    items.push({ id: 'delete', label: 'Delete', icon: Trash2, danger: true });
    return items;
};

const onAction = (b, item) => {
    if (item.id === 'view') {
        goShow(b);
    } else if (item.id === 'edit') {
        goCreate();
    } else if (item.id === 'duplicate') {
        router.post(
            route('broadcasts.store'),
            { source_id: b.id, name: b.name },
            {
                preserveScroll: true,
                onSuccess: () => toast.success('Broadcast duplicated.'),
            },
        );
    } else if (item.id === 'pause') {
        b.status = 'draft';
        b.sent = '—';
        toast.success('Schedule cancelled.');
    } else if (item.id === 'delete') {
        deleteTarget.value = b;
        showDelete.value = true;
    }
};

const confirmDelete = () => {
    if (!deleteTarget.value) return;
    router.delete(route('broadcasts.destroy', deleteTarget.value.id), {
        onSuccess: () => {
            showDelete.value = false;
            deleteTarget.value = null;
            toast.info('Broadcast deleted.');
        },
    });
};
</script>

<template>
    <Head title="Broadcasts" />

    <AppLayout>
        <PageHeader
            title="Broadcasts"
            description="One-off campaigns to segments of your audience."
        >
            <template #actions>
                <button type="button" class="md-btn-solid" @click="goCreate">
                    <Plus :size="16" />
                    New broadcast
                </button>
            </template>
        </PageHeader>

        <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center">
            <div class="relative min-w-0 flex-1">
                <Search
                    :size="15"
                    class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-zinc-500"
                />
                <input
                    v-model="search"
                    class="md-input pl-9"
                    placeholder="Search broadcasts…"
                />
            </div>
            <select v-model="status" class="md-input w-full sm:w-40">
                <option value="all">All statuses</option>
                <option value="sent">Sent</option>
                <option value="scheduled">Scheduled</option>
                <option value="draft">Draft</option>
            </select>
        </div>

        <EmptyState
            v-if="!filtered.length"
            title="No broadcasts found"
            description="Create a campaign or clear your filters."
        >
            <template #icon>
                <Megaphone :size="28" :stroke-width="1.5" />
            </template>
            <template #actions>
                <button type="button" class="md-btn-solid" @click="goCreate">
                    <Plus :size="16" />
                    Create broadcast
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
                        <th class="px-4 py-3 font-medium">Audience</th>
                        <th class="px-4 py-3 font-medium">Recipients</th>
                        <th class="px-4 py-3 font-medium">Open rate</th>
                        <th class="px-4 py-3 font-medium">Status</th>
                        <th class="px-4 py-3 font-medium">Sent</th>
                        <th class="w-12 px-4 py-3" />
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-900">
                    <tr
                        v-for="b in filtered"
                        :key="b.id"
                        class="cursor-pointer transition hover:bg-zinc-900/50"
                        @click="goShow(b)"
                    >
                        <td class="px-4 py-3 font-medium text-white">
                            <Link
                                :href="route('broadcasts.show', b.id)"
                                class="hover:text-cyan-300"
                                @click.stop
                            >
                                {{ b.name }}
                            </Link>
                        </td>
                        <td class="px-4 py-3 text-zinc-400">{{ b.audience }}</td>
                        <td class="px-4 py-3 tabular-nums text-zinc-300">
                            {{ b.recipients.toLocaleString() }}
                        </td>
                        <td class="px-4 py-3 text-zinc-400">{{ b.open_rate }}</td>
                        <td class="px-4 py-3">
                            <StatusBadge :status="b.status" />
                        </td>
                        <td class="px-4 py-3 text-zinc-500">{{ b.sent }}</td>
                        <td class="px-4 py-3 text-right" @click.stop>
                            <RowActions
                                :items="actionsFor(b)"
                                @select="onAction(b, $event)"
                            />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Modal
            :show="showDelete"
            title="Delete broadcast?"
            :description="
                deleteTarget
                    ? `“${deleteTarget.name}” will be removed.`
                    : ''
            "
            @close="showDelete = false"
        >
            <p class="text-sm text-zinc-400">This cannot be undone.</p>
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
                    Delete
                </button>
            </template>
        </Modal>
    </AppLayout>
</template>
