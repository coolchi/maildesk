<script setup>
import { computed, ref, watchEffect } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import Modal from '@/Components/Modal.vue';
import ProviderTestButton from '@/Components/Admin/ProviderTestButton.vue';
import { providerDriverPresets } from '@/lib/mailProviders';
import { usePlatform } from '@/composables/usePlatform';
import { useToast } from '@/composables/useToast';
import {
    Check,
    Eye,
    EyeOff,
    Plus,
    Server,
    Star,
    Trash2,
} from '@lucide/vue';

const props = defineProps({
    providers: { type: Array, default: () => [] },
});

const toast = useToast();
const { providers, activeProviders, hydrate } = usePlatform();

watchEffect(() => {
    hydrate({ providers: props.providers });
});

const selectedId = ref(
    props.providers.find((p) => p.default)?.id || props.providers[0]?.id,
);

watchEffect(() => {
    if (
        selectedId.value &&
        !providers.value.some((p) => p.id === selectedId.value)
    ) {
        selectedId.value = providers.value[0]?.id || null;
    }
});

const showAdd = ref(false);
const showDelete = ref(false);
const deleteTarget = ref(null);
const revealed = ref({});

const addForm = ref({
    name: '',
    driver: 'resend',
    config: [],
});

const selected = computed(
    () =>
        providers.value.find((p) => p.id === selectedId.value) ||
        providers.value[0],
);

const activeCount = computed(() => activeProviders.value.length);

const driverOptions = computed(() =>
    Object.entries(providerDriverPresets).map(([id, preset]) => ({
        id,
        label: preset.label,
    })),
);

const blankConfigForDriver = (driver) => {
    const preset = providerDriverPresets[driver];
    return (preset?.configKeys || []).map((k) => ({
        key: k.key,
        value: '',
        secret: k.secret,
        placeholder: k.placeholder || '',
    }));
};

const openAdd = () => {
    addForm.value = {
        name: '',
        driver: 'resend',
        config: blankConfigForDriver('resend'),
    };
    showAdd.value = true;
};

const onDriverChange = () => {
    addForm.value.config = blankConfigForDriver(addForm.value.driver);
    const preset = providerDriverPresets[addForm.value.driver];
    if (preset && !addForm.value.name.trim()) {
        addForm.value.name = `${preset.label} account`;
    }
};

const createProvider = () => {
    const name = addForm.value.name.trim();
    if (!name) {
        toast.error('Enter a name for this provider account.');
        return;
    }
    const driver = addForm.value.driver;
    const preset = providerDriverPresets[driver];
    if (!preset) {
        toast.error('Choose a provider type.');
        return;
    }

    router.post(
        route('admin.providers.store'),
        {
            name,
            driver,
            type: preset.type,
            api_base: preset.apiBase,
            description: preset.description,
            regions: preset.regions,
            features: preset.features,
            config: addForm.value.config.map((c) => ({
                key: c.key,
                value: c.value.trim(),
                secret: c.secret,
            })),
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                showAdd.value = false;
                toast.success(`Added “${name}”.`);
            },
            onError: () => toast.error('Could not create provider.'),
        },
    );
};

const saveProvider = () => {
    if (!selected.value?.dbId) return;
    if (!selected.value.name?.trim()) {
        toast.error('Name is required.');
        return;
    }

    router.put(
        route('admin.providers.update', selected.value.dbId),
        {
            name: selected.value.name.trim(),
            status: selected.value.status,
            api_base: selected.value.apiBase,
            description: selected.value.description,
            is_default: selected.value.default,
            config: selected.value.config,
        },
        {
            preserveScroll: true,
            onSuccess: () => toast.success(`${selected.value.name} config saved.`),
            onError: () => toast.error('Could not save provider.'),
        },
    );
};

const setDefault = (provider) => {
    if (!provider.dbId) return;
    router.put(
        route('admin.providers.update', provider.dbId),
        {
            name: provider.name,
            status: provider.status,
            api_base: provider.apiBase,
            description: provider.description,
            is_default: true,
            config: provider.config,
        },
        {
            preserveScroll: true,
            onSuccess: () =>
                toast.success(`${provider.name} is now the platform default.`),
            onError: () => toast.error('Could not set default.'),
        },
    );
};

const toggleStatus = (provider) => {
    if (provider.default && provider.status === 'active') {
        toast.error('Switch default provider before disabling.');
        return;
    }
    if (!provider.dbId) return;
    const next = provider.status === 'active' ? 'disabled' : 'active';
    router.put(
        route('admin.providers.update', provider.dbId),
        {
            name: provider.name,
            status: next,
            api_base: provider.apiBase,
            description: provider.description,
            config: provider.config,
        },
        {
            preserveScroll: true,
            onSuccess: () =>
                toast.success(
                    next === 'active'
                        ? `${provider.name} enabled.`
                        : `${provider.name} disabled.`,
                ),
            onError: () => toast.error('Could not update status.'),
        },
    );
};

const addConfigRow = () => {
    if (!selected.value) return;
    selected.value.config.push({
        key: '',
        value: '',
        secret: false,
    });
};

const removeConfigRow = (index) => {
    if (!selected.value) return;
    selected.value.config.splice(index, 1);
};

const revealKey = (providerId, key) => `${providerId}:${key}`;

const toggleReveal = (providerId, key) => {
    const id = revealKey(providerId, key);
    revealed.value = {
        ...revealed.value,
        [id]: !revealed.value[id],
    };
};

const askDelete = (provider) => {
    if (provider.default) {
        toast.error('Set another default before deleting this account.');
        return;
    }
    deleteTarget.value = provider;
    showDelete.value = true;
};

const confirmDelete = () => {
    if (!deleteTarget.value?.dbId) return;
    const name = deleteTarget.value.name;
    router.delete(route('admin.providers.destroy', deleteTarget.value.dbId), {
        preserveScroll: true,
        onSuccess: () => {
            toast.info(`Deleted “${name}”.`);
            showDelete.value = false;
            deleteTarget.value = null;
        },
        onError: () => toast.error('Could not delete provider.'),
    });
};

const inputType = (row, providerId) => {
    if (!row.secret) return 'text';
    return revealed.value[revealKey(providerId, row.key)] ? 'text' : 'password';
};
</script>

<template>
    <Head title="Admin · Providers" />

    <AdminLayout>
        <PageHeader
            title="Mail providers"
            description="Manage provider accounts, API keys, and SMTP credentials tenants can use."
        >
            <template #actions>
                <span class="text-sm text-zinc-500">
                    {{ activeCount }} active · {{ providers.length }} total
                </span>
                <button type="button" class="md-btn-solid" @click="openAdd">
                    <Plus :size="16" />
                    Add provider
                </button>
            </template>
        </PageHeader>

        <div
            v-if="!providers.length"
            class="md-card flex flex-col items-center gap-3 px-6 py-16 text-center"
        >
            <Server :size="28" class="text-zinc-600" />
            <div>
                <div class="text-sm font-medium text-white">
                    No provider accounts
                </div>
                <p class="mt-1 text-sm text-zinc-500">
                    Add Resend, SES, SMTP, or another delivery backend.
                </p>
            </div>
            <button type="button" class="md-btn-solid" @click="openAdd">
                <Plus :size="16" />
                Add provider
            </button>
        </div>

        <div v-else class="grid gap-6 lg:grid-cols-[280px_minmax(0,1fr)]">
            <aside class="space-y-1">
                <button
                    v-for="p in providers"
                    :key="p.id"
                    type="button"
                    class="flex w-full items-start gap-3 rounded-xl border px-3 py-3 text-left transition"
                    :class="
                        selectedId === p.id
                            ? 'border-cyan-400/40 bg-cyan-400/10'
                            : 'border-zinc-800 hover:border-zinc-700 hover:bg-zinc-900/50'
                    "
                    @click="selectedId = p.id"
                >
                    <span
                        class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-zinc-900 text-cyan-300"
                    >
                        <Server :size="14" />
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="flex items-center gap-1.5">
                            <span
                                class="truncate text-sm font-medium text-white"
                                >{{ p.name }}</span
                            >
                            <Star
                                v-if="p.default"
                                :size="12"
                                class="fill-amber-400 text-amber-400"
                            />
                        </span>
                        <span class="mt-0.5 block text-[11px] text-zinc-500">
                            {{ (p.driver || p.type).toUpperCase() }} ·
                            {{ p.accounts.toLocaleString() }} tenants
                        </span>
                    </span>
                    <StatusBadge :status="p.status" />
                </button>
            </aside>

            <section v-if="selected" class="md-card space-y-5 p-5 sm:p-6">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <input
                                v-model="selected.name"
                                class="md-input max-w-sm text-base font-semibold text-white"
                            />
                            <StatusBadge :status="selected.status" />
                            <span
                                v-if="selected.default"
                                class="rounded-full bg-amber-500/15 px-2 py-0.5 text-[11px] font-medium text-amber-300"
                            >
                                Default
                            </span>
                        </div>
                        <p class="mt-2 max-w-xl text-sm text-zinc-500">
                            {{ selected.description }}
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-if="
                                !selected.default &&
                                selected.status === 'active'
                            "
                            type="button"
                            class="md-btn-ghost"
                            @click="setDefault(selected)"
                        >
                            <Star :size="14" />
                            Make default
                        </button>
                        <button
                            type="button"
                            class="md-btn-ghost"
                            @click="toggleStatus(selected)"
                        >
                            {{
                                selected.status === 'active'
                                    ? 'Disable'
                                    : 'Enable'
                            }}
                        </button>
                        <ProviderTestButton :provider="selected" />
                        <button
                            type="button"
                            class="md-btn-ghost text-rose-300 hover:border-rose-500/40 hover:text-rose-200"
                            @click="askDelete(selected)"
                        >
                            <Trash2 :size="14" />
                            Delete
                        </button>
                        <button
                            type="button"
                            class="md-btn-solid"
                            @click="saveProvider"
                        >
                            <Check :size="14" />
                            Save
                        </button>
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-3">
                    <div class="rounded-xl border border-zinc-800 p-4">
                        <div
                            class="text-xs uppercase tracking-wide text-zinc-500"
                        >
                            Driver
                        </div>
                        <div
                            class="mt-1 text-sm font-medium uppercase text-white"
                        >
                            {{ selected.driver || selected.type }}
                        </div>
                    </div>
                    <div class="rounded-xl border border-zinc-800 p-4">
                        <div
                            class="text-xs uppercase tracking-wide text-zinc-500"
                        >
                            Tenant accounts
                        </div>
                        <div
                            class="mt-1 text-sm font-medium tabular-nums text-white"
                        >
                            {{ selected.accounts.toLocaleString() }}
                        </div>
                    </div>
                    <div class="rounded-xl border border-zinc-800 p-4">
                        <div
                            class="text-xs uppercase tracking-wide text-zinc-500"
                        >
                            Regions
                        </div>
                        <div class="mt-1 text-sm font-medium text-white">
                            {{ selected.regions.join(', ') }}
                        </div>
                    </div>
                </div>

                <div>
                    <label class="mb-1.5 block text-xs text-zinc-500"
                        >API base URL</label
                    >
                    <input
                        v-model="selected.apiBase"
                        class="md-input font-mono text-xs"
                        placeholder="n/a (SMTP)"
                    />
                </div>

                <div>
                    <div class="mb-3 flex items-center justify-between gap-3">
                        <div>
                            <div
                                class="text-xs font-medium uppercase tracking-wide text-zinc-500"
                            >
                                Config keys
                            </div>
                            <p class="mt-0.5 text-xs text-zinc-600">
                                API keys, tokens, and SMTP credentials for this
                                account.
                            </p>
                        </div>
                        <button
                            type="button"
                            class="md-btn-ghost text-xs"
                            @click="addConfigRow"
                        >
                            <Plus :size="14" />
                            Add key
                        </button>
                    </div>

                    <div class="space-y-2">
                        <div
                            v-for="(row, index) in selected.config"
                            :key="`${selected.id}-${index}`"
                            class="grid gap-2 rounded-xl border border-zinc-800 bg-black/30 p-3 sm:grid-cols-[minmax(0,0.9fr)_minmax(0,1.4fr)_auto]"
                        >
                            <input
                                v-model="row.key"
                                class="md-input font-mono text-xs uppercase"
                                placeholder="KEY_NAME"
                            />
                            <div class="relative">
                                <input
                                    v-model="row.value"
                                    class="md-input pr-10 font-mono text-xs"
                                    :type="inputType(row, selected.id)"
                                    :placeholder="
                                        row.placeholder ||
                                        (row.secret
                                            ? row.hasValue
                                                ? 'Saved (leave blank to keep)'
                                                : '••••••••'
                                            : 'value')
                                    "
                                    autocomplete="off"
                                />
                                <button
                                    v-if="row.secret"
                                    type="button"
                                    class="absolute right-2 top-1/2 -translate-y-1/2 rounded p-1 text-zinc-500 hover:text-zinc-200"
                                    @click="toggleReveal(selected.id, row.key)"
                                >
                                    <EyeOff
                                        v-if="
                                            revealed[
                                                revealKey(selected.id, row.key)
                                            ]
                                        "
                                        :size="14"
                                    />
                                    <Eye v-else :size="14" />
                                </button>
                            </div>
                            <div class="flex items-center gap-2">
                                <label
                                    class="flex cursor-pointer items-center gap-1.5 text-[11px] text-zinc-500"
                                >
                                    <input
                                        v-model="row.secret"
                                        type="checkbox"
                                        class="rounded border-zinc-700"
                                    />
                                    Secret
                                </label>
                                <button
                                    type="button"
                                    class="rounded-lg p-2 text-zinc-500 hover:bg-zinc-900 hover:text-rose-300"
                                    title="Remove key"
                                    @click="removeConfigRow(index)"
                                >
                                    <Trash2 :size="14" />
                                </button>
                            </div>
                        </div>
                        <p
                            v-if="!selected.config.length"
                            class="rounded-xl border border-dashed border-zinc-800 px-4 py-6 text-center text-sm text-zinc-500"
                        >
                            No config keys yet. Add an API key or SMTP field.
                        </p>
                    </div>
                </div>

                <div>
                    <div
                        class="mb-2 text-xs font-medium uppercase tracking-wide text-zinc-500"
                    >
                        Capabilities
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <span
                            v-for="f in selected.features"
                            :key="f"
                            class="rounded-full border border-zinc-800 bg-zinc-950 px-2.5 py-1 text-xs text-zinc-300"
                        >
                            {{ f }}
                        </span>
                    </div>
                </div>
            </section>
        </div>

        <Modal
            :show="showAdd"
            title="Add mail provider"
            description="Create a provider account and set its config keys."
            max-width="lg"
            @close="showAdd = false"
        >
            <div class="space-y-4">
                <div>
                    <label class="mb-1.5 block text-xs text-zinc-500"
                        >Provider type</label
                    >
                    <select
                        v-model="addForm.driver"
                        class="md-input"
                        @change="onDriverChange"
                    >
                        <option
                            v-for="opt in driverOptions"
                            :key="opt.id"
                            :value="opt.id"
                        >
                            {{ opt.label }}
                        </option>
                    </select>
                </div>
                <div>
                    <label class="mb-1.5 block text-xs text-zinc-500"
                        >Account name</label
                    >
                    <input
                        v-model="addForm.name"
                        class="md-input"
                        placeholder="Resend Production"
                    />
                </div>
                <div>
                    <div
                        class="mb-2 text-xs font-medium uppercase tracking-wide text-zinc-500"
                    >
                        Config keys
                    </div>
                    <div class="space-y-2">
                        <div
                            v-for="(row, index) in addForm.config"
                            :key="`${row.key}-${index}`"
                            class="grid gap-2 sm:grid-cols-2"
                        >
                            <div
                                class="flex items-center rounded-lg border border-zinc-800 bg-zinc-950 px-3 text-xs font-mono uppercase text-zinc-400"
                            >
                                {{ row.key }}
                            </div>
                            <input
                                v-model="row.value"
                                class="md-input font-mono text-xs"
                                :type="row.secret ? 'password' : 'text'"
                                :placeholder="row.placeholder || 'value'"
                                autocomplete="off"
                            />
                        </div>
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
                </button>
                <button
                    type="button"
                    class="md-btn-solid"
                    @click="createProvider"
                >
                    <Plus :size="14" />
                    Add provider
                </button>
            </template>
        </Modal>

        <Modal
            :show="showDelete"
            title="Delete provider account?"
            :description="
                deleteTarget
                    ? `“${deleteTarget.name}” will be removed from the platform catalog.`
                    : ''
            "
            @close="showDelete = false"
        >
            <p class="text-sm text-zinc-400">
                Tenants still assigned to this provider will show as orphaned
                until you reassign them. This cannot be undone.
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
                    Delete provider
                </button>
            </template>
        </Modal>
    </AdminLayout>
</template>
