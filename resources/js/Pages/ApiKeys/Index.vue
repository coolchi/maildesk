<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Modal from '@/Components/Modal.vue';
import EmptyState from '@/Components/EmptyState.vue';
import { useToast } from '@/composables/useToast';
import {
    CircleHelp,
    Code2,
    Copy,
    Download,
    KeyRound,
    MoreHorizontal,
    Pencil,
    Plus,
    Search,
    Trash2,
} from '@lucide/vue';

const props = defineProps({
    keys: { type: Array, default: () => [] },
    domainOptions: { type: Array, default: () => ['All domains'] },
    plainApiKey: { type: String, default: null },
});

const toast = useToast();
const page = usePage();
const keys = ref(props.keys.map((k) => ({ ...k })));
const search = ref('');
const permissionFilter = ref('all');
const menuId = ref(null);

const showCreate = ref(false);
const showEdit = ref(false);
const showReveal = ref(false);
const showDelete = ref(false);

const form = ref({
    name: '',
    permission: 'Full access',
    domain: 'All domains',
});
const editTarget = ref(null);
const deleteTarget = ref(null);
const plainKey = ref('');
const perPage = ref(40);
const saving = ref(false);

watch(
    () => props.keys,
    (value) => {
        keys.value = value.map((k) => ({ ...k }));
    },
);

watch(
    () => props.plainApiKey ?? page.props.flash?.plain_api_key,
    (value) => {
        if (value) {
            plainKey.value = value;
            showReveal.value = true;
        }
    },
    { immediate: true },
);

watch(
    () => page.props.flash?.success,
    (message) => {
        if (message) toast.success(message);
    },
);

const domainOptions = computed(() => props.domainOptions);

const filtered = computed(() => {
    const q = search.value.trim().toLowerCase();
    return keys.value.filter((k) => {
        const permOk =
            permissionFilter.value === 'all' ||
            k.permission === permissionFilter.value;
        const searchOk =
            !q ||
            k.name.toLowerCase().includes(q) ||
            k.prefix.toLowerCase().includes(q);
        return permOk && searchOk;
    });
});

const closeMenu = () => {
    menuId.value = null;
};

const onKeySave = (e) => {
    if ((e.metaKey || e.ctrlKey) && e.key === 'Enter') {
        if (showCreate.value) createKey();
        if (showEdit.value) saveEdit();
    }
};

onMounted(() => {
    document.addEventListener('click', closeMenu);
    window.addEventListener('keydown', onKeySave);
});

onUnmounted(() => {
    document.removeEventListener('click', closeMenu);
    window.removeEventListener('keydown', onKeySave);
});

const openCreate = () => {
    form.value = {
        name: '',
        permission: 'Full access',
        domain: 'All domains',
    };
    showCreate.value = true;
};

const createKey = () => {
    if (!form.value.name.trim()) {
        toast.error('Enter a name for the API key.');
        return;
    }
    saving.value = true;
    router.post(
        route('api-keys.store'),
        {
            name: form.value.name.trim(),
            permission: form.value.permission,
            domain: form.value.domain,
        },
        {
            onFinish: () => {
                saving.value = false;
            },
            onSuccess: () => {
                showCreate.value = false;
            },
            onError: (errors) => {
                toast.error(errors.name || 'Could not create API key.');
            },
        },
    );
};

const openEdit = (key) => {
    editTarget.value = key;
    form.value = {
        name: key.name,
        permission: key.permission || 'Full access',
        domain: key.domain || 'All domains',
    };
    menuId.value = null;
    showEdit.value = true;
};

const saveEdit = () => {
    if (!editTarget.value || !form.value.name.trim()) return;
    saving.value = true;
    router.put(
        route('api-keys.update', editTarget.value.id),
        {
            name: form.value.name.trim(),
            permission: form.value.permission,
            domain: form.value.domain,
        },
        {
            onFinish: () => {
                saving.value = false;
            },
            onSuccess: () => {
                showEdit.value = false;
            },
        },
    );
};

const copyKey = async () => {
    try {
        await navigator.clipboard.writeText(plainKey.value);
        toast.success('Copied to clipboard.');
    } catch {
        toast.error('Could not copy.');
    }
};

const askDelete = (key) => {
    deleteTarget.value = key;
    menuId.value = null;
    showDelete.value = true;
};

const confirmDelete = () => {
    if (!deleteTarget.value) return;
    router.delete(route('api-keys.destroy', deleteTarget.value.id), {
        onSuccess: () => {
            showDelete.value = false;
            deleteTarget.value = null;
        },
    });
};

const exportCsv = () => {
    toast.info('Export coming soon.');
};
</script>

<template>
    <Head title="API Keys" />

    <AppLayout>
        <PageHeader title="API keys">
            <template #actions>
                <button type="button" class="md-btn-solid" @click="openCreate">
                    <Plus :size="16" />
                    Create API key
                </button>
                <Link
                    :href="route('docs')"
                    class="md-btn-ghost !px-2.5"
                    title="API docs"
                >
                    <Code2 :size="16" />
                </Link>
            </template>
        </PageHeader>

        <EmptyState
            v-if="!keys.length"
            title="No API keys yet"
            description="Create a key to send email from your app with Authorization: Bearer md_…"
        >
            <template #icon>
                <KeyRound :size="28" :stroke-width="1.5" />
            </template>
            <template #actions>
                <button type="button" class="md-btn-solid" @click="openCreate">
                    <Plus :size="16" />
                    Create API key
                </button>
            </template>
        </EmptyState>

        <div
            v-else-if="keys.length"
            class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center"
        >
            <div class="relative min-w-0 flex-1">
                <Search
                    :size="15"
                    class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-zinc-500"
                />
                <input
                    v-model="search"
                    class="md-input pl-9"
                    placeholder="Search…"
                />
            </div>
            <select v-model="permissionFilter" class="md-input w-full sm:w-44">
                <option value="all">All permissions</option>
                <option value="Full access">Full access</option>
                <option value="Sending access">Sending access</option>
            </select>
            <button
                type="button"
                class="md-btn-ghost !px-2.5"
                title="Export"
                @click="exportCsv"
            >
                <Download :size="16" />
            </button>
        </div>

        <EmptyState
            v-if="keys.length && !filtered.length"
            title="No keys match"
            description="Try another search or permission filter."
        >
            <template #icon>
                <Search :size="28" :stroke-width="1.5" />
            </template>
        </EmptyState>

        <template v-else-if="keys.length">
            <div class="md-table-wrap">
                <table class="min-w-full text-left text-sm">
                    <thead
                        class="border-b border-zinc-800 text-xs text-zinc-500"
                    >
                        <tr>
                            <th class="px-4 py-3 font-medium">Name</th>
                            <th class="px-4 py-3 font-medium">Token</th>
                            <th class="px-4 py-3 font-medium">Permission</th>
                            <th class="px-4 py-3 font-medium">Last used</th>
                            <th class="px-4 py-3 font-medium">Created</th>
                            <th class="w-12 px-4 py-3" />
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-900">
                        <tr
                            v-for="key in filtered"
                            :key="key.id"
                            class="hover:bg-white/[0.03]"
                        >
                            <td class="px-4 py-3.5">
                                <div class="flex items-center gap-2.5">
                                    <span
                                        class="flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-cyan-400/15 text-cyan-300"
                                    >
                                        <KeyRound :size="14" />
                                    </span>
                                    <span class="font-medium text-white">{{
                                        key.name
                                    }}</span>
                                </div>
                            </td>
                            <td class="px-4 py-3.5 font-mono text-xs text-zinc-400">
                                {{ key.prefix }}…
                            </td>
                            <td class="px-4 py-3.5 text-zinc-400">
                                {{ key.permission }}
                            </td>
                            <td class="px-4 py-3.5 text-zinc-500">
                                <span
                                    v-if="key.last_used === 'Never'"
                                    class="inline-flex items-center gap-1"
                                >
                                    No activity
                                    <CircleHelp
                                        :size="12"
                                        class="text-zinc-600"
                                        title="This key has never been used"
                                    />
                                </span>
                                <span v-else>{{ key.last_used }}</span>
                            </td>
                            <td class="px-4 py-3.5 text-zinc-500">
                                {{ key.created }}
                            </td>
                            <td class="relative px-4 py-3.5 text-right">
                                <button
                                    type="button"
                                    class="rounded-md p-1.5 text-zinc-500 transition hover:bg-zinc-800 hover:text-zinc-200"
                                    @click.stop="
                                        menuId =
                                            menuId === key.id ? null : key.id
                                    "
                                >
                                    <MoreHorizontal :size="16" />
                                </button>
                                <div
                                    v-if="menuId === key.id"
                                    class="absolute right-4 z-30 mt-1 w-44 overflow-hidden rounded-xl border border-zinc-800 bg-zinc-950 py-1 shadow-2xl shadow-black/60"
                                    @click.stop
                                >
                                    <button
                                        type="button"
                                        class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-zinc-300 hover:bg-zinc-900"
                                        @click="openEdit(key)"
                                    >
                                        <Pencil
                                            :size="14"
                                            class="text-zinc-500"
                                        />
                                        Edit API key
                                    </button>
                                    <button
                                        type="button"
                                        class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-rose-400 hover:bg-zinc-900"
                                        @click="askDelete(key)"
                                    >
                                        <Trash2 :size="14" />
                                        Delete API key
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="mt-3 text-xs text-zinc-500">
                Page 1 · {{ filtered.length }} of {{ keys.length }} keys ·
                <select
                    v-model.number="perPage"
                    class="ml-1 rounded border-0 bg-transparent text-zinc-400 outline-none"
                >
                    <option :value="10">10 items</option>
                    <option :value="40">40 items</option>
                    <option :value="100">100 items</option>
                </select>
            </div>
        </template>

        <!-- Create -->
        <Modal
            :show="showCreate"
            title="Create API key"
            description="Name the key and choose its permission scope."
            @close="showCreate = false"
        >
            <div class="space-y-4">
                <div>
                    <label class="mb-1.5 block text-xs text-zinc-500">Name</label>
                    <input
                        v-model="form.name"
                        class="md-input"
                        placeholder="Production"
                        autofocus
                    />
                </div>
                <div>
                    <label
                        class="mb-1.5 inline-flex items-center gap-1 text-xs text-zinc-500"
                    >
                        Permission
                        <CircleHelp :size="12" class="text-zinc-600" />
                    </label>
                    <select v-model="form.permission" class="md-input">
                        <option>Full access</option>
                        <option>Sending access</option>
                    </select>
                </div>
                <div>
                    <label class="mb-1.5 block text-xs text-zinc-500"
                        >Domain</label
                    >
                    <select v-model="form.domain" class="md-input">
                        <option
                            v-for="d in domainOptions"
                            :key="d"
                            :value="d"
                        >
                            {{ d }}
                        </option>
                    </select>
                </div>
            </div>
            <template #footer>
                <button
                    type="button"
                    class="md-btn-ghost"
                    @click="showCreate = false"
                >
                    Cancel
                    <kbd class="ml-1 text-[10px] text-zinc-600">Esc</kbd>
                </button>
                <button type="button" class="md-btn-primary" @click="createKey">
                    Create
                    <kbd class="ml-1 text-[10px] opacity-60">⌘↵</kbd>
                </button>
            </template>
        </Modal>

        <!-- Edit -->
        <Modal
            :show="showEdit"
            title="Edit API Key"
            @close="showEdit = false"
        >
            <div class="space-y-4">
                <div>
                    <label class="mb-1.5 block text-xs text-zinc-500">Name</label>
                    <input v-model="form.name" class="md-input" autofocus />
                </div>
                <div>
                    <label
                        class="mb-1.5 inline-flex items-center gap-1 text-xs text-zinc-500"
                    >
                        Permission
                        <CircleHelp :size="12" class="text-zinc-600" />
                    </label>
                    <select v-model="form.permission" class="md-input">
                        <option>Full access</option>
                        <option>Sending access</option>
                    </select>
                </div>
                <div>
                    <label class="mb-1.5 block text-xs text-zinc-500"
                        >Domain</label
                    >
                    <select v-model="form.domain" class="md-input">
                        <option
                            v-for="d in domainOptions"
                            :key="d"
                            :value="d"
                        >
                            {{ d }}
                        </option>
                    </select>
                </div>
            </div>
            <template #footer>
                <button
                    type="button"
                    class="md-btn-ghost"
                    @click="showEdit = false"
                >
                    Cancel
                    <kbd class="ml-1 text-[10px] text-zinc-600">Esc</kbd>
                </button>
                <button type="button" class="md-btn-primary" @click="saveEdit">
                    Save
                    <kbd class="ml-1 text-[10px] opacity-60">⌘↵</kbd>
                </button>
            </template>
        </Modal>

        <Modal
            :show="showReveal"
            title="Copy your API key"
            description="This value is shown once. Store it securely."
            @close="showReveal = false"
        >
            <code
                class="block break-all rounded-lg border border-amber-500/20 bg-amber-500/10 p-3 font-mono text-xs text-amber-50"
                >{{ plainKey }}</code
            >
            <template #footer>
                <button type="button" class="md-btn-ghost" @click="copyKey">
                    <Copy :size="14" />
                    Copy
                </button>
                <button
                    type="button"
                    class="md-btn-primary"
                    @click="showReveal = false"
                >
                    Done
                </button>
            </template>
        </Modal>

        <Modal
            :show="showDelete"
            title="Delete API key?"
            :description="
                deleteTarget
                    ? `“${deleteTarget.name}” will stop working immediately.`
                    : ''
            "
            @close="showDelete = false"
        >
            <p class="text-sm text-zinc-400">
                Requests using this key will return 401. This cannot be undone.
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
                    Delete API key
                </button>
            </template>
        </Modal>
    </AppLayout>
</template>
