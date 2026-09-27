<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import EmptyState from '@/Components/EmptyState.vue';
import RowActions from '@/Components/RowActions.vue';
import Modal from '@/Components/Modal.vue';
import SandboxedHtml from '@/Components/SandboxedHtml.vue';
import { useToast } from '@/composables/useToast';
import {
    Copy,
    LayoutTemplate,
    Pencil,
    Plus,
    Search,
    Trash2,
} from '@lucide/vue';

const props = defineProps({
    templates: { type: Array, default: () => [] },
});

const toast = useToast();
const search = ref('');
const templates = ref([]);
watch(
    () => props.templates,
    (list) => {
        templates.value = (list || []).map((t) => ({ ...t }));
    },
    { immediate: true },
);
const showDelete = ref(false);
const deleteTarget = ref(null);

const filtered = computed(() => {
    const q = search.value.trim().toLowerCase();
    if (!q) return templates.value;
    return templates.value.filter((t) => t.name.toLowerCase().includes(q));
});

const actionsFor = () => [
    { id: 'edit', label: 'Edit', icon: Pencil },
    { id: 'duplicate', label: 'Duplicate', icon: Copy },
    { id: 'delete', label: 'Delete', icon: Trash2, danger: true },
];

const onAction = (tpl, item) => {
    if (item.id === 'edit') {
        router.visit(route('templates.edit', tpl.id));
    } else if (item.id === 'duplicate') {
        router.post(
            route('templates.store'),
            { source_id: tpl.id, name: `${tpl.name} (copy)` },
            {
                preserveScroll: true,
                onSuccess: () => toast.success('Template duplicated.'),
            },
        );
    } else if (item.id === 'delete') {
        deleteTarget.value = tpl;
        showDelete.value = true;
    }
};

const confirmDelete = () => {
    if (!deleteTarget.value) return;
    router.delete(route('templates.destroy', deleteTarget.value.id), {
        onSuccess: () => {
            showDelete.value = false;
            deleteTarget.value = null;
            toast.info('Template deleted.');
        },
    });
};

const thumbStyle = (tpl) => ({
    background: `linear-gradient(145deg, ${tpl.accent || '#22d3ee'}22, #09090b 55%)`,
});
</script>

<template>
    <Head title="Templates" />

    <AppLayout>
        <PageHeader
            title="Templates"
            description="Reusable HTML templates with live email preview."
        >
            <template #actions>
                <button
                    type="button"
                    class="md-btn-solid"
                    @click="
                        router.post(route('templates.store'), {
                            name: 'Untitled template',
                            subject: '',
                            html: '<p>Hello</p>',
                        })
                    "
                >
                    <Plus :size="16" />
                    New template
                </button>
            </template>
        </PageHeader>

        <div class="mb-4">
            <div class="relative max-w-sm">
                <Search
                    :size="15"
                    class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-zinc-500"
                />
                <input
                    v-model="search"
                    class="md-input pl-9"
                    placeholder="Search templates…"
                />
            </div>
        </div>

        <EmptyState
            v-if="!filtered.length"
            title="No templates found"
            description="Create a template or clear your search."
        >
            <template #icon>
                <LayoutTemplate :size="28" :stroke-width="1.5" />
            </template>
            <template #actions>
                <button
                    type="button"
                    class="md-btn-solid"
                    @click="
                        router.post(route('templates.store'), {
                            name: 'Untitled template',
                            subject: '',
                            html: '<p>Hello</p>',
                        })
                    "
                >
                    <Plus :size="16" />
                    New template
                </button>
            </template>
        </EmptyState>

        <div v-else class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div
                v-for="tpl in filtered"
                :key="tpl.id"
                class="md-card group relative overflow-hidden transition hover:border-cyan-400/40"
            >
                <Link
                    :href="route('templates.edit', tpl.id)"
                    class="block"
                >
                    <div
                        class="relative flex h-44 items-center justify-center overflow-hidden p-4"
                        :style="thumbStyle(tpl)"
                    >
                        <div
                            class="pointer-events-none w-full max-w-[220px] scale-[0.72] origin-top overflow-hidden rounded-lg border border-zinc-700/50 bg-white shadow-lg"
                            style="height: 160px"
                        >
                            <div
                                class="origin-top scale-[0.55] w-[180%] -translate-x-[12%]"
                            >
                                <SandboxedHtml
                                    :html="tpl.html"
                                    :min-height="300"
                                    :auto-resize="false"
                                    :title="`${tpl.name} preview`"
                                />
                            </div>
                        </div>
                        <div
                            class="pointer-events-none absolute inset-x-0 bottom-0 h-16 bg-gradient-to-t from-zinc-950 to-transparent"
                        />
                    </div>
                </Link>
                <div class="border-t border-zinc-800 p-4">
                    <div class="flex items-start justify-between gap-2">
                        <Link
                            :href="route('templates.edit', tpl.id)"
                            class="min-w-0"
                        >
                            <h3
                                class="truncate font-medium text-white hover:text-cyan-300"
                            >
                                {{ tpl.name }}
                            </h3>
                            <p class="mt-1 truncate text-xs text-zinc-500">
                                {{ tpl.subject || 'No subject' }}
                            </p>
                            <p class="mt-1 text-xs text-zinc-600">
                                Updated {{ tpl.updated }}
                            </p>
                        </Link>
                        <div class="flex shrink-0 items-center gap-1">
                            <StatusBadge :status="tpl.status" />
                            <RowActions
                                :items="actionsFor()"
                                @select="onAction(tpl, $event)"
                            />
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <Modal
            :show="showDelete"
            title="Delete template?"
            :description="
                deleteTarget ? `“${deleteTarget.name}” will be removed.` : ''
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
