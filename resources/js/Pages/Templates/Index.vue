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
import { useDesigns } from '@/composables/useDesigns';
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
    samples: { type: Array, default: () => [] },
});

const toast = useToast();
const { catalog, defaultKey, sample, apply, applyDocument } = useDesigns();
const tab = ref('content');
const search = ref('');
const savingDefault = ref(false);
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

const createTemplate = () => {
    router.post(route('templates.store'), {
        name: 'Untitled template',
        subject: '',
        html: '<p>Hello</p>',
        design_key: defaultKey.value || null,
    });
};

const usingSample = ref('');

const useSample = (sampleItem) => {
    if (usingSample.value) return;
    usingSample.value = sampleItem.key;
    router.post(
        route('templates.samples.store'),
        { sample_key: sampleItem.key },
        { onFinish: () => { usingSample.value = ''; } },
    );
};

const accents = ref({});
let colorTimer = null;

const shownAccent = (design) => accents.value[design.key] || design.accent;

const designCards = computed(() => [
    {
        key: '',
        plain: true,
        name: 'Plain',
        description: 'The message as written, with no frame.',
        accent: '#f4f4f5',
    },
    ...catalog.value,
]);

const plainDoc = `<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="margin:0;padding:28px 24px;background:#ffffff;font:16px/1.65 Segoe UI,Helvetica,Arial,sans-serif;color:#18181b;">${sample}</body></html>`;

const isDefault = (design) => (design.plain ? !defaultKey.value : defaultKey.value === design.key);

const previewDoc = (design) =>
    design.plain ? plainDoc : applyDocument(sample, design.key, shownAccent(design));

const saveColor = (key, accent) => {
    router.put(
        route('templates.design-color'),
        { design_key: key, accent },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                const next = { ...accents.value };
                delete next[key];
                accents.value = next;
            },
        },
    );
};

const onColor = (design, event) => {
    const accent = event.target.value;
    accents.value = { ...accents.value, [design.key]: accent };
    clearTimeout(colorTimer);
    colorTimer = setTimeout(() => saveColor(design.key, accent), 350);
};

const isRecolored = (design) =>
    shownAccent(design).toLowerCase() !== String(design.default_accent || design.accent).toLowerCase();

const resetColor = (design) => {
    clearTimeout(colorTimer);
    accents.value = { ...accents.value, [design.key]: design.default_accent };
    router.put(
        route('templates.design-color'),
        { design_key: design.key, reset: true },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                const next = { ...accents.value };
                delete next[design.key];
                accents.value = next;
            },
        },
    );
};

const setDefault = (key) => {
    if (savingDefault.value) return;
    savingDefault.value = true;
    router.put(
        route('templates.design-default'),
        { design_key: key },
        {
            preserveScroll: true,
            onSuccess: () =>
                toast.success(key ? 'Default design updated.' : 'Default is plain mail.'),
            onFinish: () => {
                savingDefault.value = false;
            },
        },
    );
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

const designName = (tpl) => catalog.value.find((design) => design.key === tpl.design_key)?.name || '';

const thumbStyle = (tpl) => ({
    background: `linear-gradient(145deg, ${tpl.accent || '#22d3ee'}22, #09090b 55%)`,
});
</script>

<template>
    <Head title="Templates" />

    <AppLayout>
        <PageHeader
            title="Templates"
            description="Content templates hold the message. Design templates frame it. A design is optional."
        >
            <template #actions>
                <button
                    v-if="tab === 'content'"
                    type="button"
                    class="md-btn-solid"
                    @click="createTemplate"
                >
                    <Plus :size="16" />
                    New template
                </button>
            </template>
        </PageHeader>

        <div class="mb-4 flex flex-wrap gap-2">
            <button
                type="button"
                class="rounded-full px-3 py-1.5 text-sm transition"
                :class="tab === 'content' ? 'bg-zinc-800 text-white' : 'text-zinc-500 hover:text-zinc-300'"
                @click="tab = 'content'"
            >
                Content templates
            </button>
            <button
                type="button"
                class="rounded-full px-3 py-1.5 text-sm transition"
                :class="tab === 'design' ? 'bg-zinc-800 text-white' : 'text-zinc-500 hover:text-zinc-300'"
                @click="tab = 'design'"
            >
                Design templates
            </button>
        </div>

        <div v-if="tab === 'design'" class="space-y-4">
            <p class="max-w-xl text-sm text-zinc-500">
                Choose a default, or leave mail plain.
            </p>
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <article
                    v-for="design in designCards"
                    :key="design.plain ? 'plain' : design.key"
                    class="md-card overflow-hidden"
                >
                    <div class="h-52 overflow-hidden border-b border-zinc-800 bg-zinc-900">
                        <iframe
                            class="pointer-events-none h-[420px] w-[200%] origin-top-left scale-50 border-0"
                            sandbox=""
                            :title="`${design.name} preview`"
                            :srcdoc="previewDoc(design)"
                        />
                    </div>
                    <div class="space-y-3 p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <h2 class="text-sm font-medium text-white">{{ design.name }}</h2>
                                <p class="mt-1 text-xs leading-relaxed text-zinc-500">{{ design.description }}</p>
                            </div>
                            <label
                                v-if="!design.plain"
                                class="relative mt-0.5 h-4 w-4 shrink-0 cursor-pointer overflow-hidden rounded-full ring-1 ring-white/30"
                                title="Change color"
                            >
                                <input
                                    type="color"
                                    class="absolute -inset-2 h-8 w-8 cursor-pointer border-0 bg-transparent p-0"
                                    :value="shownAccent(design)"
                                    :aria-label="`Color for ${design.name}`"
                                    @input="onColor(design, $event)"
                                />
                            </label>
                            <span
                                v-else
                                class="mt-1 h-2.5 w-2.5 shrink-0 rounded-full bg-zinc-200"
                            />
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <button
                                type="button"
                                class="md-btn-ghost !px-3 !py-1.5 text-xs"
                                :disabled="savingDefault || isDefault(design)"
                                @click="setDefault(design.plain ? null : design.key)"
                            >
                                {{ isDefault(design) ? 'Default' : 'Set as default' }}
                            </button>
                            <button
                                v-if="!design.plain"
                                type="button"
                                class="md-btn-ghost !px-3 !py-1.5 text-xs"
                                :disabled="!isRecolored(design)"
                                @click="resetColor(design)"
                            >
                                Reset
                            </button>
                        </div>
                    </div>
                </article>
            </div>
        </div>

        <div v-if="tab === 'content'" class="mb-8 space-y-4">
            <div>
                <h2 class="text-sm font-medium text-white">Samples</h2>
                <p class="mt-1 max-w-xl text-sm text-zinc-500">
                    Start from one. The words and pictures stay editable.
                </p>
            </div>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <article
                    v-for="sampleItem in samples"
                    :key="sampleItem.key"
                    class="md-card overflow-hidden"
                >
                    <div class="sample-thumb relative h-44 overflow-hidden bg-zinc-900">
                        <div
                            class="pointer-events-none absolute left-1/2 top-1/2 w-[560px] origin-center"
                            style="transform: translate(-50%, -50%) scale(0.48)"
                        >
                            <SandboxedHtml
                                :html="apply(sampleItem.html, sampleItem.design_key)"
                                :min-height="340"
                                :auto-resize="false"
                                :title="`${sampleItem.name} preview`"
                            />
                        </div>
                        <div
                            class="pointer-events-none absolute inset-x-0 bottom-0 h-12 bg-gradient-to-t from-zinc-950 to-transparent"
                        />
                    </div>
                    <div class="space-y-3 border-t border-zinc-800 p-4">
                        <div>
                            <h3 class="text-sm font-medium text-white">{{ sampleItem.name }}</h3>
                            <p class="mt-1 text-xs leading-relaxed text-zinc-500">{{ sampleItem.description }}</p>
                        </div>
                        <button
                            type="button"
                            class="md-btn-ghost !px-3 !py-1.5 text-xs"
                            :disabled="usingSample === sampleItem.key"
                            @click="useSample(sampleItem)"
                        >
                            {{ usingSample === sampleItem.key ? 'Copying…' : 'Use sample' }}
                        </button>
                    </div>
                </article>
            </div>
        </div>

        <div v-if="tab === 'content'" class="mb-4">
            <h2 class="mb-3 text-sm font-medium text-white">Your templates</h2>
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
            v-if="tab === 'content' && !filtered.length"
            title="No templates yet"
            description="Use a sample above, or start a blank one."
        >
            <template #icon>
                <LayoutTemplate :size="28" :stroke-width="1.5" />
            </template>
            <template #actions>
                <button
                    type="button"
                    class="md-btn-solid"
                    @click="createTemplate"
                >
                    <Plus :size="16" />
                    New template
                </button>
            </template>
        </EmptyState>

        <div v-else-if="tab === 'content'" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
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
                                    :html="apply(tpl.html, tpl.design_key)"
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
                            <p v-if="designName(tpl)" class="mt-1 text-xs text-zinc-600">
                                {{ designName(tpl) }}
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

<style>
.sample-thumb iframe {
    height: 340px;
}
</style>
