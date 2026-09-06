<script setup>
import { computed, ref, watchEffect } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import EmptyState from '@/Components/EmptyState.vue';
import Modal from '@/Components/Modal.vue';
import { usePlatform } from '@/composables/usePlatform';
import { useToast } from '@/composables/useToast';
import { Check, Globe2, Plus, Search } from '@lucide/vue';

const props = defineProps({
    hosts: { type: Array, default: () => [] },
    accounts: { type: Array, default: () => [] },
});

const toast = useToast();
const { subdomains, hydrate } = usePlatform();

watchEffect(() => {
    hydrate({ hosts: props.hosts });
});

const search = ref('');
const status = ref('all');
const rows = subdomains;
const showCreate = ref(false);
const saving = ref(false);
const form = ref({
    organizationId: '',
    subdomain: '',
    customHost: '',
    type: 'platform',
});

const filtered = computed(() => {
    const q = search.value.trim().toLowerCase();
    return rows.value.filter((d) => {
        const statusOk = status.value === 'all' || d.status === status.value;
        const searchOk =
            !q ||
            (d.account || '').toLowerCase().includes(q) ||
            d.host.toLowerCase().includes(q) ||
            (d.subdomain || '').toLowerCase().includes(q);
        return statusOk && searchOk;
    });
});

const openCreate = () => {
    form.value = {
        organizationId: props.accounts[0]?.id ? String(props.accounts[0].id) : '',
        subdomain: '',
        customHost: '',
        type: 'platform',
    };
    showCreate.value = true;
};

const createHost = () => {
    if (!form.value.organizationId) {
        toast.error('Choose an account.');
        return;
    }
    const isCustom = form.value.type === 'custom';
    const subdomain = form.value.subdomain
        .trim()
        .toLowerCase()
        .replace(/[^a-z0-9-]/g, '');
    const host = form.value.customHost.trim().toLowerCase();

    if (isCustom && !host) {
        toast.error('Custom host is required.');
        return;
    }
    if (!isCustom && !subdomain) {
        toast.error('Subdomain is required.');
        return;
    }

    saving.value = true;
    router.post(
        route('admin.subdomains.store'),
        {
            organization_id: Number(form.value.organizationId),
            type: form.value.type,
            subdomain: isCustom ? null : subdomain,
            host: isCustom ? host : null,
        },
        {
            preserveScroll: true,
            onFinish: () => {
                saving.value = false;
            },
            onSuccess: () => {
                showCreate.value = false;
                toast.success('Host queued.');
            },
            onError: (errors) => {
                toast.error(
                    errors.host ||
                        errors.subdomain ||
                        errors.organization_id ||
                        'Could not create host.',
                );
            },
        },
    );
};

const verify = (row) => {
    router.post(
        route('admin.subdomains.verify', row.id),
        {},
        {
            preserveScroll: true,
            onSuccess: () => toast.success(`${row.host} verified.`),
            onError: () => toast.error('Could not verify host.'),
        },
    );
};
</script>

<template>
    <Head title="Admin · Subdomains" />

    <AdminLayout>
        <PageHeader
            title="Subdomains"
            description="Platform subdomains and customer custom hosts."
        >
            <template #actions>
                <button type="button" class="md-btn-solid" @click="openCreate">
                    <Plus :size="16" />
                    Add host
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
                    placeholder="Search hosts…"
                />
            </div>
            <select v-model="status" class="md-input w-full sm:w-44">
                <option value="all">All statuses</option>
                <option value="active">Active</option>
                <option value="pending_dns">Pending DNS</option>
                <option value="provisioning">Provisioning</option>
            </select>
        </div>

        <EmptyState
            v-if="!filtered.length"
            title="No hosts found"
            description="Add a platform subdomain or custom domain for an account."
        >
            <template #icon>
                <Globe2 :size="28" :stroke-width="1.5" />
            </template>
            <template #actions>
                <button type="button" class="md-btn-solid" @click="openCreate">
                    <Plus :size="16" />
                    Add host
                </button>
            </template>
        </EmptyState>

        <div v-else class="md-table-wrap">
            <table class="min-w-full text-left text-sm">
                <thead
                    class="border-b border-zinc-800 text-xs uppercase tracking-wide text-zinc-500"
                >
                    <tr>
                        <th class="px-4 py-3 font-medium">Account</th>
                        <th class="px-4 py-3 font-medium">Host</th>
                        <th class="px-4 py-3 font-medium">Status</th>
                        <th class="px-4 py-3 font-medium">SSL</th>
                        <th class="px-4 py-3 font-medium">Created</th>
                        <th class="w-28 px-4 py-3" />
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-900">
                    <tr
                        v-for="d in filtered"
                        :key="d.id"
                        class="hover:bg-white/[0.03]"
                    >
                        <td class="px-4 py-3">
                            <Link
                                :href="route('admin.accounts.show', d.accountId)"
                                class="font-medium text-zinc-200 hover:text-cyan-300"
                            >
                                {{ d.account }}
                            </Link>
                        </td>
                        <td class="px-4 py-3 font-mono text-xs text-zinc-300">
                            {{ d.host }}
                        </td>
                        <td class="px-4 py-3">
                            <StatusBadge :status="d.status" />
                        </td>
                        <td class="px-4 py-3 text-zinc-400">
                            {{ d.ssl ? 'Yes' : 'No' }}
                        </td>
                        <td class="px-4 py-3 text-zinc-500">{{ d.created }}</td>
                        <td class="px-4 py-3 text-right">
                            <button
                                v-if="d.status !== 'active'"
                                type="button"
                                class="inline-flex items-center gap-1 text-xs text-cyan-300 hover:text-cyan-200"
                                @click="verify(d)"
                            >
                                <Check :size="12" />
                                Verify
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Modal
            :show="showCreate"
            title="Add host"
            description="Create a *.maildesk.test subdomain or attach a custom domain."
            @close="showCreate = false"
        >
            <div class="space-y-3">
                <div>
                    <label class="mb-1.5 block text-xs text-zinc-500"
                        >Account</label
                    >
                    <select v-model="form.organizationId" class="md-input">
                        <option disabled value="">Select account…</option>
                        <option
                            v-for="account in accounts"
                            :key="account.id"
                            :value="String(account.id)"
                        >
                            {{ account.name }}
                        </option>
                    </select>
                </div>
                <div class="inline-flex rounded-full border border-zinc-800 p-1">
                    <button
                        type="button"
                        class="rounded-full px-3 py-1 text-xs transition"
                        :class="
                            form.type === 'platform'
                                ? 'bg-zinc-800 text-white'
                                : 'text-zinc-500'
                        "
                        @click="form.type = 'platform'"
                    >
                        Platform subdomain
                    </button>
                    <button
                        type="button"
                        class="rounded-full px-3 py-1 text-xs transition"
                        :class="
                            form.type === 'custom'
                                ? 'bg-zinc-800 text-white'
                                : 'text-zinc-500'
                        "
                        @click="form.type = 'custom'"
                    >
                        Custom domain
                    </button>
                </div>
                <div v-if="form.type === 'platform'">
                    <label class="mb-1.5 block text-xs text-zinc-500"
                        >Subdomain</label
                    >
                    <div class="flex items-center gap-2">
                        <input
                            v-model="form.subdomain"
                            class="md-input"
                            placeholder="acme"
                        />
                        <span class="shrink-0 text-sm text-zinc-500"
                            >.maildesk.test</span
                        >
                    </div>
                </div>
                <div v-else>
                    <label class="mb-1.5 block text-xs text-zinc-500"
                        >Custom host</label
                    >
                    <input
                        v-model="form.customHost"
                        class="md-input"
                        placeholder="mail.customer.com"
                    />
                </div>
            </div>
            <template #footer>
                <button
                    type="button"
                    class="md-btn-ghost"
                    @click="showCreate = false"
                >
                    Cancel
                </button>
                <button
                    type="button"
                    class="md-btn-solid"
                    :disabled="saving"
                    @click="createHost"
                >
                    Create
                </button>
            </template>
        </Modal>
    </AdminLayout>
</template>
