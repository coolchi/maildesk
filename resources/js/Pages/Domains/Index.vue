<script setup>
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Modal from '@/Components/Modal.vue';
import EmptyState from '@/Components/EmptyState.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import RowActions from '@/Components/RowActions.vue';
import { useToast } from '@/composables/useToast';
import { useComposeModal } from '@/composables/useComposeModal';
import {
    Copy,
    Globe,
    Pencil,
    Plus,
    RefreshCw,
    Trash2,
} from '@lucide/vue';

const props = defineProps({
    domains: { type: Array, default: () => [] },
});

const toast = useToast();
const page = usePage();
const { open: openCompose } = useComposeModal();
const domains = ref(props.domains.map((d) => ({ ...d })));
const showAdd = ref(false);
const showDelete = ref(false);
const name = ref('');
const deleteTarget = ref(null);
const saving = ref(false);

watch(
    () => props.domains,
    (value) => {
        domains.value = value.map((d) => ({ ...d }));
    },
);

watch(
    () => page.props.flash?.success,
    (message) => {
        if (message) toast.success(message);
    },
    { immediate: true },
);

const addDomain = () => {
    const domain = name.value.trim().toLowerCase();
    if (!domain || !domain.includes('.')) {
        toast.error('Enter a valid domain like mail.acme.com');
        return;
    }
    saving.value = true;
    router.post(
        route('domains.store'),
        { name: domain },
        {
            onFinish: () => {
                saving.value = false;
            },
            onSuccess: () => {
                showAdd.value = false;
                name.value = '';
            },
            onError: (errors) => {
                toast.error(errors.name || 'Could not add domain.');
            },
        },
    );
};

const actionsFor = (domain) => {
    const items = [
        {
            id: 'manage',
            label: domain.status === 'verified' ? 'Manage DNS' : 'Verify DNS',
            icon: Pencil,
        },
        { id: 'copy', label: 'Copy domain', icon: Copy },
    ];
    if (domain.status === 'verified') {
        items.push({ id: 'compose', label: 'Compose from domain', icon: Plus });
    } else {
        items.push({ id: 'recheck', label: 'Recheck DNS', icon: RefreshCw });
    }
    items.push({ id: 'delete', label: 'Delete domain', icon: Trash2, danger: true });
    return items;
};

const onAction = (domain, item) => {
    if (item.id === 'manage') {
        router.visit(route('domains.show', domain.id));
    } else if (item.id === 'copy') {
        navigator.clipboard
            .writeText(domain.name)
            .then(() => toast.success('Domain copied.'))
            .catch(() => toast.error('Copy failed.'));
    } else if (item.id === 'compose') {
        openCompose({ from: `hello@${domain.name}` });
    } else if (item.id === 'recheck') {
        router.post(route('domains.verify', domain.id), {}, {
            preserveScroll: true,
            onSuccess: () => toast.success(`${domain.name} verified.`),
        });
    } else if (item.id === 'delete') {
        deleteTarget.value = domain;
        showDelete.value = true;
    }
};

const confirmDelete = () => {
    if (!deleteTarget.value) return;
    router.delete(route('domains.destroy', deleteTarget.value.id), {
        onSuccess: () => {
            showDelete.value = false;
            deleteTarget.value = null;
        },
    });
};

const empty = computed(() => !domains.value.length);
</script>

<template>
    <Head title="Domains" />

    <AppLayout>
        <PageHeader
            title="Domains"
            description="Verify sending domains for SPF, DKIM, and DMARC."
        >
            <template #actions>
                <button
                    type="button"
                    class="md-btn-solid"
                    @click="showAdd = true"
                >
                    <Plus :size="16" />
                    Add domain
                </button>
            </template>
        </PageHeader>

        <EmptyState
            v-if="empty"
            title="No domains yet"
            description="Add a domain to start sending from your brand addresses."
        >
            <template #icon>
                <Globe :size="28" :stroke-width="1.5" />
            </template>
            <template #actions>
                <button
                    type="button"
                    class="md-btn-solid"
                    @click="showAdd = true"
                >
                    <Plus :size="16" />
                    Add domain
                </button>
            </template>
        </EmptyState>

        <div v-else class="md-table-wrap">
            <table class="min-w-full text-left text-sm">
                <thead
                    class="border-b border-zinc-800 text-xs uppercase tracking-wide text-zinc-500"
                >
                    <tr>
                        <th class="px-4 py-3 font-medium">Domain</th>
                        <th class="px-4 py-3 font-medium">Status</th>
                        <th class="px-4 py-3 font-medium">Region</th>
                        <th class="px-4 py-3 font-medium">Created</th>
                        <th class="px-4 py-3 font-medium">DNS</th>
                        <th class="w-12 px-4 py-3" />
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-900">
                    <tr
                        v-for="domain in domains"
                        :key="domain.id"
                        class="hover:bg-white/[0.03]"
                    >
                        <td class="px-4 py-3 font-medium text-white">
                            <Link
                                :href="route('domains.show', domain.id)"
                                class="hover:text-cyan-300"
                            >
                                {{ domain.name }}
                            </Link>
                        </td>
                        <td class="px-4 py-3">
                            <StatusBadge
                                :status="domain.status"
                                :loading="domain.status === 'pending'"
                            />
                        </td>
                        <td class="px-4 py-3 text-zinc-400">
                            {{ domain.region }}
                        </td>
                        <td class="px-4 py-3 text-zinc-500">
                            {{ domain.created }}
                        </td>
                        <td class="px-4 py-3">
                            <Link
                                :href="route('domains.show', domain.id)"
                                class="text-cyan-400 hover:text-cyan-300"
                            >
                                {{
                                    domain.status === 'verified'
                                        ? 'Manage'
                                        : 'Verify'
                                }}
                            </Link>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <RowActions
                                :items="actionsFor(domain)"
                                @select="onAction(domain, $event)"
                            />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Modal
            :show="showAdd"
            title="Add domain"
            description="We'll generate DNS records for you to publish."
            @close="showAdd = false"
        >
            <label class="mb-1.5 block text-xs text-zinc-500">Domain name</label>
            <input
                v-model="name"
                class="md-input"
                placeholder="mail.acme.com"
                @keyup.enter="addDomain"
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
                    :disabled="saving"
                    @click="addDomain"
                >
                    Continue
                </button>
            </template>
        </Modal>

        <Modal
            :show="showDelete"
            title="Delete domain?"
            :description="
                deleteTarget
                    ? `“${deleteTarget.name}” will stop being usable for sending.`
                    : ''
            "
            @close="showDelete = false"
        >
            <p class="text-sm text-zinc-400">
                Existing emails are unaffected. You can re-add the domain later.
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
                    Delete domain
                </button>
            </template>
        </Modal>
    </AppLayout>
</template>
