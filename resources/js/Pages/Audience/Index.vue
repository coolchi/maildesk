<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import EmptyState from '@/Components/EmptyState.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import Modal from '@/Components/Modal.vue';
import RowActions from '@/Components/RowActions.vue';
import { useToast } from '@/composables/useToast';
import {
    Ban,
    Code2,
    Copy,
    Download,
    FolderKanban,
    Mail,
    Pencil,
    Plus,
    Search,
    Tags,
    Trash2,
    Upload,
    UserMinus,
    Users,
} from '@lucide/vue';

const props = defineProps({
    contacts: { type: Array, default: () => [] },
    segments: { type: Array, default: () => [] },
    properties: { type: Array, default: () => [] },
    topics: { type: Array, default: () => [] },
});

const toast = useToast();
const tab = ref('contacts');
const search = ref('');
const statusFilter = ref('all');
const subFilter = ref('all');
const showEmpty = ref(false);
const showAdd = ref(false);
const showDelete = ref(false);
const newEmail = ref('');
const deleteTarget = ref(null);
const deleteKind = ref('contact');

const tabs = [
    { id: 'contacts', label: 'Contacts' },
    { id: 'properties', label: 'Properties' },
    { id: 'segments', label: 'Segments' },
    { id: 'topics', label: 'Topics' },
];

const contacts = ref([]);
const properties = ref([]);
const segments = ref([]);
const topics = ref([]);

watch(
    () => [props.contacts, props.segments, props.properties, props.topics],
    () => {
        contacts.value = (props.contacts || []).map((c) => ({ ...c }));
        segments.value = (props.segments || []).map((s) => ({ ...s }));
        properties.value = (props.properties || []).map((p) => ({ ...p }));
        topics.value = (props.topics || []).map((t) => ({ ...t }));
    },
    { immediate: true },
);

const filteredContacts = computed(() => {
    return contacts.value.filter((c) => {
        const q = search.value.trim().toLowerCase();
        const searchOk =
            !q ||
            c.email.toLowerCase().includes(q) ||
            `${c.first_name} ${c.last_name}`.toLowerCase().includes(q);
        const statusOk =
            statusFilter.value === 'all' || c.status === statusFilter.value;
        const subOk =
            subFilter.value === 'all' ||
            (subFilter.value === 'subscribed' && c.status === 'subscribed') ||
            (subFilter.value === 'unsubscribed' && c.status === 'unsubscribed');
        return searchOk && statusOk && subOk;
    });
});

const stats = computed(() => ({
    all: contacts.value.length,
    subscribers: contacts.value.filter((c) => c.status === 'subscribed').length,
    unsubscribers: contacts.value.filter((c) => c.status === 'unsubscribed')
        .length,
}));

const contactActions = (c) => [
    { id: 'edit', label: 'Edit contact', icon: Pencil },
    {
        id: 'toggle',
        label: c.status === 'subscribed' ? 'Unsubscribe' : 'Resubscribe',
        icon: c.status === 'subscribed' ? UserMinus : Mail,
    },
    { id: 'suppress', label: 'Add to suppressions', icon: Ban },
    { id: 'delete', label: 'Delete', icon: Trash2, danger: true },
];

const propertyActions = () => [
    { id: 'edit', label: 'Edit property', icon: Pencil },
    { id: 'delete', label: 'Delete', icon: Trash2, danger: true },
];

const segmentActions = () => [
    { id: 'edit', label: 'Edit segment', icon: Pencil },
    { id: 'duplicate', label: 'Duplicate', icon: Copy },
    { id: 'delete', label: 'Delete', icon: Trash2, danger: true },
];

const topicActions = () => [
    { id: 'edit', label: 'Edit topic', icon: Pencil },
    { id: 'delete', label: 'Delete', icon: Trash2, danger: true },
];

const addContact = () => {
    if (!newEmail.value.trim()) return;
    router.post(
        route('audience.store'),
        { email: newEmail.value.trim() },
        {
            preserveScroll: true,
            onSuccess: () => {
                newEmail.value = '';
                showAdd.value = false;
                showEmpty.value = false;
                toast.success('Contact added.');
            },
            onError: () => toast.error('Could not add contact.'),
        },
    );
};

const onContactAction = (row, item) => {
    if (item.id === 'edit') {
        toast.info('Edit contact coming soon.');
    } else if (item.id === 'toggle') {
        row.status =
            row.status === 'subscribed' ? 'unsubscribed' : 'subscribed';
        toast.success(
            row.status === 'subscribed' ? 'Resubscribed.' : 'Unsubscribed.',
        );
    } else if (item.id === 'suppress') {
        router.post(route('audience.suppress', row.id), {}, {
            preserveScroll: true,
            onSuccess: () => toast.success('Added to suppressions.'),
        });
    } else if (item.id === 'delete') {
        deleteKind.value = 'contact';
        deleteTarget.value = row;
        showDelete.value = true;
    }
};

const onPropertyAction = (prop, item) => {
    if (item.id === 'edit') toast.info('Edit property coming soon.');
    else if (item.id === 'delete') {
        deleteKind.value = 'property';
        deleteTarget.value = prop;
        showDelete.value = true;
    }
};

const onSegmentAction = (seg, item) => {
    if (item.id === 'edit') toast.info('Edit segment coming soon.');
    else if (item.id === 'duplicate') {
        segments.value.unshift({
            ...seg,
            id: Date.now(),
            name: `${seg.name} (copy)`,
            updated: 'just now',
        });
        toast.success('Segment duplicated.');
    } else if (item.id === 'delete') {
        deleteKind.value = 'segment';
        deleteTarget.value = seg;
        showDelete.value = true;
    }
};

const onTopicAction = (topic, item) => {
    if (item.id === 'edit') toast.info('Edit topic coming soon.');
    else if (item.id === 'delete') {
        deleteKind.value = 'topic';
        deleteTarget.value = topic;
        showDelete.value = true;
    }
};

const confirmDelete = () => {
    if (!deleteTarget.value) return;
    if (deleteKind.value === 'contact') {
        router.delete(route('audience.destroy', deleteTarget.value.id), {
            onSuccess: () => {
                showDelete.value = false;
                deleteTarget.value = null;
                toast.info('Deleted.');
            },
        });
        return;
    }
    if (deleteKind.value === 'property') {
        properties.value = properties.value.filter(
            (p) => p.id !== deleteTarget.value.id,
        );
    } else if (deleteKind.value === 'segment') {
        segments.value = segments.value.filter(
            (s) => s.id !== deleteTarget.value.id,
        );
    } else if (deleteKind.value === 'topic') {
        topics.value = topics.value.filter(
            (t) => t.id !== deleteTarget.value.id,
        );
    }
    toast.info('Deleted.');
    showDelete.value = false;
    deleteTarget.value = null;
};

const deleteLabel = computed(() => {
    if (!deleteTarget.value) return '';
    return (
        deleteTarget.value.email ||
        deleteTarget.value.name ||
        'this item'
    );
});
</script>

<template>
    <Head title="Audience" />

    <AppLayout>
        <PageHeader title="Audience">
            <template #actions>
                <Link :href="route('docs')" class="md-btn-ghost" title="API">
                    <Code2 :size="16" />
                </Link>
                <button
                    type="button"
                    class="md-btn-solid"
                    @click="showAdd = true"
                >
                    <Plus :size="16" />
                    Add contacts
                </button>
            </template>
        </PageHeader>

        <div class="mb-8 flex flex-wrap gap-5 border-b border-zinc-900 text-sm">
            <button
                v-for="t in tabs"
                :key="t.id"
                type="button"
                class="relative pb-3 transition"
                :class="
                    tab === t.id
                        ? 'font-medium text-white'
                        : 'text-zinc-500 hover:text-zinc-300'
                "
                @click="tab = t.id"
            >
                {{ t.label }}
                <span
                    v-if="tab === t.id"
                    class="absolute inset-x-0 -bottom-px h-0.5 bg-cyan-400"
                />
            </button>
        </div>

        <div v-if="tab === 'contacts'">
            <div class="mb-6 flex flex-wrap gap-8 sm:gap-12">
                <div>
                    <div
                        class="text-[11px] uppercase tracking-wide text-zinc-500"
                    >
                        All contacts
                    </div>
                    <div
                        class="mt-1 text-3xl font-semibold tabular-nums text-white"
                    >
                        {{ stats.all }}
                    </div>
                </div>
                <div>
                    <div
                        class="text-[11px] uppercase tracking-wide text-zinc-500"
                    >
                        Subscribers
                    </div>
                    <div
                        class="mt-1 text-3xl font-semibold tabular-nums text-white"
                    >
                        {{ stats.subscribers }}
                    </div>
                </div>
                <div>
                    <div
                        class="text-[11px] uppercase tracking-wide text-zinc-500"
                    >
                        Unsubscribers
                    </div>
                    <div
                        class="mt-1 text-3xl font-semibold tabular-nums text-white"
                    >
                        {{ stats.unsubscribers }}
                    </div>
                </div>
            </div>

            <div class="mb-4 flex flex-col gap-3 lg:flex-row lg:items-center">
                <div class="relative grow">
                    <Search
                        :size="16"
                        class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-zinc-500"
                    />
                    <input
                        v-model="search"
                        type="search"
                        class="md-input pl-9"
                        placeholder="Search by name, email…"
                    />
                </div>
                <select v-model="statusFilter" class="md-input lg:w-40">
                    <option value="all">All contacts</option>
                    <option value="subscribed">Subscribed</option>
                    <option value="unsubscribed">Unsubscribed</option>
                </select>
                <select v-model="subFilter" class="md-input lg:w-44">
                    <option value="all">All subscriptions</option>
                    <option value="subscribed">Subscribers only</option>
                    <option value="unsubscribed">Unsubscribers only</option>
                </select>
                <button type="button" class="md-btn-ghost" title="Export">
                    <Download :size="16" />
                </button>
            </div>

            <div class="mb-3 flex justify-end">
                <button
                    type="button"
                    class="text-xs text-zinc-500 hover:text-zinc-300"
                    @click="showEmpty = !showEmpty"
                >
                    Toggle empty state
                </button>
            </div>

            <EmptyState
                v-if="showEmpty || !contacts.length"
                title="No contacts yet"
                description="Add contacts to manage, segment, and reach your audience."
            >
                <template #icon>
                    <Users :size="28" :stroke-width="1.5" />
                </template>
                <template #actions>
                    <button
                        type="button"
                        class="md-btn-solid"
                        @click="showAdd = true"
                    >
                        <Plus :size="16" />
                        Add contacts
                    </button>
                    <button type="button" class="md-btn-ghost">
                        <Upload :size="16" />
                        Import contacts
                    </button>
                </template>
            </EmptyState>

            <div v-else class="md-table-wrap">
                <table class="min-w-full text-left text-sm">
                    <thead
                        class="border-b border-zinc-800 text-xs uppercase tracking-wide text-zinc-500"
                    >
                        <tr>
                            <th class="px-4 py-3 font-medium">Email</th>
                            <th class="px-4 py-3 font-medium">Name</th>
                            <th class="px-4 py-3 font-medium">Status</th>
                            <th class="px-4 py-3 font-medium">Company</th>
                            <th class="px-4 py-3 font-medium">Added</th>
                            <th class="w-12 px-4 py-3" />
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-900">
                        <tr
                            v-for="row in filteredContacts"
                            :key="row.id"
                            class="hover:bg-white/[0.03]"
                        >
                            <td class="px-4 py-3 font-medium text-zinc-200">
                                {{ row.email }}
                            </td>
                            <td class="px-4 py-3 text-zinc-400">
                                {{
                                    [row.first_name, row.last_name]
                                        .filter(Boolean)
                                        .join(' ') || '—'
                                }}
                            </td>
                            <td class="px-4 py-3">
                                <StatusBadge :status="row.status" />
                            </td>
                            <td class="px-4 py-3 text-zinc-500">
                                {{ row.properties?.company || '—' }}
                            </td>
                            <td class="px-4 py-3 text-zinc-500">
                                {{ row.added }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                <RowActions
                                    :items="contactActions(row)"
                                    @select="onContactAction(row, $event)"
                                />
                            </td>
                        </tr>
                        <tr v-if="!filteredContacts.length">
                            <td
                                colspan="6"
                                class="px-4 py-10 text-center text-zinc-500"
                            >
                                No contacts match your filters.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-else-if="tab === 'properties'">
            <div class="mb-4 flex justify-end">
                <button type="button" class="md-btn-solid">
                    <Plus :size="16" />
                    Add property
                </button>
            </div>
            <div class="md-table-wrap">
                <table class="min-w-full text-left text-sm">
                    <thead
                        class="border-b border-zinc-800 text-xs uppercase tracking-wide text-zinc-500"
                    >
                        <tr>
                            <th class="px-4 py-3 font-medium">Property</th>
                            <th class="px-4 py-3 font-medium">Type</th>
                            <th class="px-4 py-3 font-medium">Contacts</th>
                            <th class="w-12 px-4 py-3" />
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-900">
                        <tr
                            v-for="prop in properties"
                            :key="prop.id"
                            class="hover:bg-white/[0.03]"
                        >
                            <td class="px-4 py-3 font-mono text-cyan-300">
                                {{ prop.name }}
                            </td>
                            <td class="px-4 py-3 text-zinc-400">
                                {{ prop.type }}
                            </td>
                            <td class="px-4 py-3 text-zinc-500">
                                {{ prop.contacts }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                <RowActions
                                    :items="propertyActions()"
                                    @select="onPropertyAction(prop, $event)"
                                />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-else-if="tab === 'segments'">
            <div class="mb-4 flex justify-end">
                <button type="button" class="md-btn-solid">
                    <Plus :size="16" />
                    Create segment
                </button>
            </div>
            <div v-if="!segments.length">
                <EmptyState
                    title="No segments yet"
                    description="Group contacts with rules for targeted broadcasts."
                >
                    <template #icon>
                        <FolderKanban :size="28" :stroke-width="1.5" />
                    </template>
                </EmptyState>
            </div>
            <div v-else class="grid gap-4 md:grid-cols-2">
                <div
                    v-for="seg in segments"
                    :key="seg.id"
                    class="md-card p-5"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h3 class="font-medium text-white">{{ seg.name }}</h3>
                            <p class="mt-2 font-mono text-xs text-zinc-400">
                                {{ seg.rule }}
                            </p>
                            <p class="mt-3 text-xs text-zinc-600">
                                {{ seg.count }} contacts · Updated
                                {{ seg.updated }}
                            </p>
                        </div>
                        <RowActions
                            :items="segmentActions()"
                            @select="onSegmentAction(seg, $event)"
                        />
                    </div>
                </div>
            </div>
        </div>

        <div v-else>
            <div class="mb-4 flex justify-end">
                <button type="button" class="md-btn-solid">
                    <Plus :size="16" />
                    Add topic
                </button>
            </div>
            <div class="grid gap-4 md:grid-cols-3">
                <div
                    v-for="topic in topics"
                    :key="topic.id"
                    class="md-card p-5"
                >
                    <div class="mb-3 flex items-start justify-between">
                        <div
                            class="flex h-9 w-9 items-center justify-center rounded-lg border border-zinc-800 text-cyan-300"
                        >
                            <Tags :size="16" />
                        </div>
                        <RowActions
                            :items="topicActions()"
                            @select="onTopicAction(topic, $event)"
                        />
                    </div>
                    <h3 class="font-medium text-white">{{ topic.name }}</h3>
                    <p class="mt-1 text-sm text-zinc-400">
                        {{ topic.description }}
                    </p>
                    <p class="mt-3 text-xs text-zinc-500">
                        {{ topic.subscribers }} subscribers
                    </p>
                </div>
            </div>
        </div>

        <Modal
            :show="showAdd"
            title="Add contact"
            description="Enter an email to add to your audience."
            max-width="md"
            @close="showAdd = false"
        >
            <input
                v-model="newEmail"
                type="email"
                class="md-input"
                placeholder="name@company.com"
                @keyup.enter="addContact"
            />
            <template #footer>
                <button
                    type="button"
                    class="md-btn-ghost"
                    @click="showAdd = false"
                >
                    Cancel
                </button>
                <button
                    type="button"
                    class="md-btn-primary"
                    @click="addContact"
                >
                    Add contact
                </button>
            </template>
        </Modal>

        <Modal
            :show="showDelete"
            title="Delete?"
            :description="`Remove “${deleteLabel}”?`"
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
