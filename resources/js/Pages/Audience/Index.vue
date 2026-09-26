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
const showImport = ref(false);
const showSegment = ref(false);
const showDelete = ref(false);
const blankContactRow = () => ({ email: '', name: '', company: '' });
const contactRows = ref([blankContactRow()]);
const importFile = ref(null);
const importInput = ref(null);
const importing = ref(false);
const savingContacts = ref(false);
const segmentName = ref('');
const segmentDescription = ref('');
const segmentStatusRule = ref('subscribed');
const segmentDomain = ref('');
const deleteTarget = ref(null);
const deleteKind = ref('contact');

const tabs = [
    { id: 'contacts', label: 'Contacts' },
    { id: 'segments', label: 'Segments' },
    { id: 'properties', label: 'Properties (later)' },
    { id: 'topics', label: 'Topics (later)' },
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
            `${c.first_name} ${c.last_name}`.toLowerCase().includes(q) ||
            (c.company || c.properties?.company || '').toLowerCase().includes(q);
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

const segmentActions = () => [
    { id: 'edit', label: 'Edit segment', icon: Pencil },
    { id: 'delete', label: 'Delete', icon: Trash2, danger: true },
];

const openAddModal = () => {
    contactRows.value = [blankContactRow()];
    showAdd.value = true;
};

const addContactRow = () => {
    if (contactRows.value.length >= 100) {
        toast.error('You can add up to 100 contacts at once.');
        return;
    }
    contactRows.value.push(blankContactRow());
};

const removeContactRow = (index) => {
    if (contactRows.value.length === 1) {
        contactRows.value = [blankContactRow()];
        return;
    }
    contactRows.value.splice(index, 1);
};

const addContact = () => {
    const contacts = contactRows.value
        .map((row) => ({
            email: row.email.trim(),
            name: row.name.trim() || null,
            company: row.company.trim() || null,
        }))
        .filter((row) => row.email !== '');

    if (!contacts.length) {
        toast.error('Add at least one email.');
        return;
    }

    savingContacts.value = true;
    router.post(
        route('audience.store'),
        { contacts },
        {
            preserveScroll: true,
            onSuccess: () => {
                contactRows.value = [blankContactRow()];
                showAdd.value = false;
                showEmpty.value = false;
                toast.success(
                    contacts.length === 1
                        ? 'Contact added.'
                        : `${contacts.length} contacts added.`,
                );
            },
            onError: (errors) =>
                toast.error(
                    errors['contacts.0.email'] ||
                        errors.contacts ||
                        Object.values(errors)[0] ||
                        'Could not add contacts.',
                ),
            onFinish: () => {
                savingContacts.value = false;
            },
        },
    );
};

const openImportModal = () => {
    importFile.value = null;
    if (importInput.value) {
        importInput.value.value = '';
    }
    showImport.value = true;
};

const onImportFile = (event) => {
    importFile.value = event.target.files?.[0] || null;
};

const importContacts = () => {
    if (!importFile.value) {
        toast.error('Choose a CSV file to import.');
        return;
    }

    importing.value = true;
    router.post(
        route('audience.import'),
        { file: importFile.value },
        {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: (page) => {
                showImport.value = false;
                showEmpty.value = false;
                importFile.value = null;
                if (importInput.value) {
                    importInput.value.value = '';
                }
                toast.success(page.props.flash?.success || 'Contacts imported.');
            },
            onError: (errors) =>
                toast.error(
                    errors.file ||
                        Object.values(errors)[0] ||
                        'Could not import contacts.',
                ),
            onFinish: () => {
                importing.value = false;
            },
        },
    );
};

const segmentRulesPayload = () => {
    const rules = [
        { field: 'meta.status', op: 'eq', value: segmentStatusRule.value },
    ];
    if (segmentDomain.value.trim()) {
        rules.push({
            field: 'email_domain',
            op: 'contains',
            value: segmentDomain.value.trim(),
        });
    }
    return rules;
};

const addSegment = () => {
    if (!segmentName.value.trim()) return;
    router.post(
        route('audience.segments.store'),
        {
            name: segmentName.value.trim(),
            description: segmentDescription.value.trim() || null,
            rules: segmentRulesPayload(),
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                segmentName.value = '';
                segmentDescription.value = '';
                segmentStatusRule.value = 'subscribed';
                segmentDomain.value = '';
                showSegment.value = false;
                tab.value = 'segments';
                toast.success('Segment created.');
            },
            onError: () => toast.error('Could not create segment.'),
        },
    );
};

const onContactAction = (row, item) => {
    if (item.id === 'edit') {
        toast.info('Edit contact coming soon.');
    } else if (item.id === 'toggle') {
        const next =
            row.status === 'subscribed' ? 'unsubscribed' : 'subscribed';
        router.patch(
            route('audience.update', row.id),
            { status: next },
            {
                preserveScroll: true,
                onSuccess: () =>
                    toast.success(
                        next === 'subscribed' ? 'Resubscribed.' : 'Unsubscribed.',
                    ),
                onError: () => toast.error('Could not update contact.'),
            },
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

const onSegmentAction = (seg, item) => {
    if (item.id === 'edit') {
        toast.info('Edit segment coming soon.');
    } else if (item.id === 'delete') {
        deleteKind.value = 'segment';
        deleteTarget.value = seg;
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
    if (deleteKind.value === 'segment') {
        router.delete(route('audience.segments.destroy', deleteTarget.value.id), {
            onSuccess: () => {
                showDelete.value = false;
                deleteTarget.value = null;
                toast.info('Deleted.');
            },
        });
        return;
    }
    toast.info('Coming later.');
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
                    class="md-btn-ghost"
                    @click="openImportModal"
                >
                    <Upload :size="16" />
                    Import
                </button>
                <button
                    type="button"
                    class="md-btn-solid"
                    @click="openAddModal"
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
                        @click="openAddModal"
                    >
                        <Plus :size="16" />
                        Add contacts
                    </button>
                    <button type="button" class="md-btn-ghost" @click="openImportModal">
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
                                {{ row.company || row.properties?.company || '—' }}
                            </td>
                            <td class="px-4 py-3 text-zinc-500">
                                {{ row.added || row.created }}
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

        <div v-else-if="tab === 'segments'">
            <div class="mb-4 flex justify-end">
                <button
                    type="button"
                    class="md-btn-solid"
                    @click="showSegment = true"
                >
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
                    <template #actions>
                        <button
                            type="button"
                            class="md-btn-solid"
                            @click="showSegment = true"
                        >
                            <Plus :size="16" />
                            Create segment
                        </button>
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
                                {{ seg.rule || 'Manual membership' }}
                            </p>
                            <p class="mt-3 text-xs text-zinc-600">
                                {{ seg.count ?? seg.contacts }} contacts · Updated
                                {{ seg.updated || seg.created }}
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

        <div v-else-if="tab === 'properties'">
            <EmptyState
                title="Properties coming later"
                description="Custom contact properties will live here in a future release."
            >
                <template #icon>
                    <Tags :size="28" :stroke-width="1.5" />
                </template>
            </EmptyState>
        </div>

        <div v-else>
            <EmptyState
                title="Topics coming later"
                description="Subscription topics will live here in a future release."
            >
                <template #icon>
                    <Tags :size="28" :stroke-width="1.5" />
                </template>
            </EmptyState>
        </div>

        <Modal
            :show="showAdd"
            title="Add contacts"
            description="Add one or more people with email, name, and company."
            max-width="xl"
            @close="showAdd = false"
        >
            <div class="space-y-3">
                <div
                    v-for="(row, index) in contactRows"
                    :key="index"
                    class="grid gap-2 rounded-lg border border-zinc-800 bg-zinc-950/40 p-3 sm:grid-cols-[1.2fr_1fr_1fr_auto]"
                >
                    <input
                        v-model="row.email"
                        type="email"
                        class="md-input"
                        placeholder="name@company.com"
                        :data-testid="`contact-email-${index}`"
                    />
                    <input
                        v-model="row.name"
                        type="text"
                        class="md-input"
                        placeholder="Name"
                        :data-testid="`contact-name-${index}`"
                    />
                    <input
                        v-model="row.company"
                        type="text"
                        class="md-input"
                        placeholder="Company"
                        :data-testid="`contact-company-${index}`"
                    />
                    <button
                        type="button"
                        class="md-btn-ghost !px-2"
                        title="Remove row"
                        :disabled="contactRows.length === 1 && !row.email && !row.name && !row.company"
                        @click="removeContactRow(index)"
                    >
                        <Trash2 :size="16" />
                    </button>
                </div>
                <button
                    type="button"
                    class="md-btn-ghost text-xs"
                    data-testid="add-contact-row"
                    @click="addContactRow"
                >
                    <Plus :size="14" />
                    Add another contact
                </button>
            </div>
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
                    data-testid="save-contacts"
                    :disabled="savingContacts"
                    @click="addContact"
                >
                    {{ savingContacts ? 'Saving…' : 'Add contacts' }}
                </button>
            </template>
        </Modal>

        <Modal
            :show="showImport"
            title="Import contacts"
            description="Upload a CSV with columns email, name, and company. First/last name columns also work."
            max-width="md"
            @close="showImport = false"
        >
            <div class="space-y-3">
                <input
                    ref="importInput"
                    type="file"
                    accept=".csv,text/csv,text/plain"
                    class="block w-full text-sm text-zinc-400 file:mr-3 file:rounded-lg file:border-0 file:bg-zinc-800 file:px-3 file:py-2 file:text-sm file:font-medium file:text-white hover:file:bg-zinc-700"
                    data-testid="import-file"
                    @change="onImportFile"
                />
                <p class="text-xs text-zinc-500">
                    Example header:
                    <code class="text-zinc-300">email,name,company</code>
                    · max 2&nbsp;MB · up to 1,000 rows
                </p>
            </div>
            <template #footer>
                <button
                    type="button"
                    class="md-btn-ghost"
                    @click="showImport = false"
                >
                    Cancel
                </button>
                <button
                    type="button"
                    class="md-btn-primary"
                    data-testid="import-contacts"
                    :disabled="importing || !importFile"
                    @click="importContacts"
                >
                    <Upload :size="16" />
                    {{ importing ? 'Importing…' : 'Import contacts' }}
                </button>
            </template>
        </Modal>

        <Modal
            :show="showSegment"
            title="Create segment"
            description="Name the segment and optionally filter by status or email domain."
            max-width="md"
            @close="showSegment = false"
        >
            <div class="space-y-3">
                <input
                    v-model="segmentName"
                    type="text"
                    class="md-input"
                    placeholder="Segment name"
                    @keyup.enter="addSegment"
                />
                <input
                    v-model="segmentDescription"
                    type="text"
                    class="md-input"
                    placeholder="Description (optional)"
                />
                <select v-model="segmentStatusRule" class="md-input">
                    <option value="subscribed">Status is subscribed</option>
                    <option value="unsubscribed">Status is unsubscribed</option>
                </select>
                <input
                    v-model="segmentDomain"
                    type="text"
                    class="md-input"
                    placeholder="Email domain contains (optional)"
                />
            </div>
            <template #footer>
                <button
                    type="button"
                    class="md-btn-ghost"
                    @click="showSegment = false"
                >
                    Cancel
                </button>
                <button
                    type="button"
                    class="md-btn-primary"
                    @click="addSegment"
                >
                    Create segment
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
