<script setup>
import { computed, ref, watchEffect } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import Modal from '@/Components/Modal.vue';
import { providerHealthForAccount } from '@/data/adminMock';
import { usePlatform } from '@/composables/usePlatform';
import AccountUsersPanel from '@/Components/Admin/AccountUsersPanel.vue';
import DeleteAccountPanel from '@/Components/Admin/DeleteAccountPanel.vue';
import { useToast } from '@/composables/useToast';
import {
    ArrowLeft,
    Ban,
    Building2,
    Check,
    Globe2,
    Pencil,
    Server,
} from '@lucide/vue';

const props = defineProps({
    id: { type: [String, Number], required: true },
    account: { type: Object, default: null },
    subscription: { type: Object, default: null },
    hosts: { type: Array, default: () => [] },
    providers: { type: Array, default: () => [] },
});

const toast = useToast();
const { hydrate, findProvider, activeProviders } = usePlatform();

watchEffect(() => {
    hydrate({
        accounts: props.account ? [props.account] : [],
        providers: props.providers,
        hosts: props.hosts,
        subscriptions: props.subscription ? [props.subscription] : [],
    });
});

const showSubdomain = ref(false);
const showProvider = ref(false);
const subdomainForm = ref({ subdomain: '', customHost: '' });
const providerId = ref('resend');

const account = computed(() => props.account);
const subscription = computed(() => props.subscription);
const hosts = computed(() => props.hosts);
const health = computed(() =>
    providerHealthForAccount(account.value, props.providers),
);

const providerLabel = computed(() => {
    if (health.value.provider) return health.value.provider.name;
    if (account.value?.provider) return `${account.value.provider} (missing)`;
    return 'Unassigned';
});

const openSubdomainModal = () => {
    subdomainForm.value = {
        subdomain: account.value.subdomain || '',
        customHost: account.value.customDomain || '',
    };
    showSubdomain.value = true;
};

const openProviderModal = () => {
    const preferred = health.value.ok
        ? account.value.provider
        : activeProviders.value[0]?.id || '';
    providerId.value = preferred;
    showProvider.value = true;
};

const saveSubdomain = () => {
    router.put(
        route('admin.accounts.hosts', props.id),
        {
            subdomain: subdomainForm.value.subdomain.trim().toLowerCase(),
            custom_domain: subdomainForm.value.customHost.trim().toLowerCase(),
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                showSubdomain.value = false;
                toast.success('Hosts updated.');
            },
            onError: (errors) => {
                toast.error(
                    errors.subdomain ||
                        errors.custom_domain ||
                        'Could not update hosts.',
                );
            },
        },
    );
};

const saveProvider = () => {
    if (!providerId.value) {
        toast.error('Choose an active provider.');
        return;
    }
    router.put(
        route('admin.accounts.provider', props.id),
        { provider: providerId.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                showProvider.value = false;
                toast.success(
                    `Mail provider set to ${findProvider(providerId.value)?.name || providerId.value}.`,
                );
            },
            onError: () => toast.error('Could not update provider.'),
        },
    );
};

const suspend = () => {
    const next =
        account.value?.status === 'suspended' ? 'active' : 'suspended';
    router.put(
        route('admin.accounts.status', props.id),
        { status: next },
        {
            preserveScroll: true,
            onSuccess: () => {
                toast.success(
                    next === 'suspended'
                        ? 'Account suspended.'
                        : 'Account reactivated.',
                );
            },
            onError: () => toast.error('Could not update account status.'),
        },
    );
};

const changePlan = () => router.visit(route('admin.plans'));
</script>

<template>
    <Head :title="`Admin · ${account.name}`" />

    <AdminLayout>
        <div class="mb-6">
            <Link
                :href="route('admin.accounts')"
                class="inline-flex items-center gap-1.5 text-sm text-zinc-500 hover:text-cyan-300"
            >
                <ArrowLeft :size="14" />
                Accounts
            </Link>
        </div>

        <div
            class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
        >
            <div class="flex items-start gap-4">
                <div
                    class="flex h-12 w-12 items-center justify-center rounded-xl border border-cyan-400/30 bg-cyan-400/10 text-cyan-300"
                >
                    <Building2 :size="22" />
                </div>
                <div>
                    <h1 class="text-2xl font-semibold text-white">
                        {{ account.name }}
                    </h1>
                    <p class="mt-1 text-sm text-zinc-500">
                        {{ account.owner }} · {{ account.email }}
                    </p>
                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        <StatusBadge :status="account.status" />
                        <span
                            class="rounded-full bg-zinc-800 px-2 py-0.5 text-[11px] text-zinc-400"
                        >
                            {{ account.plan }}
                        </span>
                        <span class="text-xs text-zinc-500">
                            {{ account.region }}
                        </span>
                    </div>
                </div>
            </div>
            <div class="flex flex-wrap gap-2">
                <button
                    type="button"
                    class="md-btn-ghost"
                    @click="openProviderModal"
                >
                    <Server :size="16" />
                    Provider
                </button>
                <button
                    type="button"
                    class="md-btn-ghost"
                    @click="openSubdomainModal"
                >
                    <Globe2 :size="16" />
                    Subdomain
                </button>
                <button type="button" class="md-btn-ghost" @click="changePlan">
                    <Pencil :size="16" />
                    Edit plans
                </button>
                <button type="button" class="md-btn-ghost" @click="suspend">
                    <Ban :size="16" />
                    {{
                        account.status === 'suspended'
                            ? 'Reactivate'
                            : 'Suspend'
                    }}
                </button>
            </div>
        </div>

        <div class="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="md-card p-4">
                <div class="text-xs uppercase tracking-wide text-zinc-500">
                    MRR
                </div>
                <div class="mt-1 text-2xl font-semibold tabular-nums text-white">
                    ${{ account.mrr }}
                </div>
            </div>
            <div class="md-card p-4">
                <div class="text-xs uppercase tracking-wide text-zinc-500">
                    Seats
                </div>
                <div class="mt-1 text-2xl font-semibold tabular-nums text-white">
                    {{ account.seats }}
                </div>
            </div>
            <div class="md-card p-4">
                <div class="text-xs uppercase tracking-wide text-zinc-500">
                    Emails (30d)
                </div>
                <div class="mt-1 text-2xl font-semibold tabular-nums text-white">
                    {{ account.emails30d.toLocaleString() }}
                </div>
            </div>
            <div class="md-card p-4">
                <div class="text-xs uppercase tracking-wide text-zinc-500">
                    Created
                </div>
                <div class="mt-1 text-lg font-semibold text-white">
                    {{ account.created }}
                </div>
            </div>
        </div>

        <div class="mb-6 grid gap-6 lg:grid-cols-3">
            <section class="md-card space-y-4 p-5">
                <div class="flex items-center justify-between gap-3">
                    <h2 class="text-sm font-medium text-white">
                        Mail provider
                    </h2>
                    <button
                        type="button"
                        class="text-xs text-cyan-300 hover:underline"
                        @click="openProviderModal"
                    >
                        Change
                    </button>
                </div>
                <div
                    v-if="!health.ok"
                    class="rounded-lg border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-xs text-rose-200"
                >
                    <span v-if="health.reason === 'orphaned'">
                        Assigned provider is missing. Reassign before this
                        tenant can send mail.
                    </span>
                    <span v-else-if="health.reason === 'disabled'">
                        Provider is disabled. Enable it or pick another.
                    </span>
                    <span v-else>
                        No mail provider assigned.
                    </span>
                </div>
                <div class="flex items-start gap-3">
                    <span
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-cyan-400/10 text-cyan-300"
                    >
                        <Server :size="16" />
                    </span>
                    <div>
                        <div class="text-sm font-medium text-white">
                            {{ providerLabel }}
                        </div>
                        <div class="mt-0.5 text-xs capitalize text-zinc-500">
                            {{ account.provider || 'none' }} · tenant delivery
                            backend
                        </div>
                    </div>
                </div>
                <Link
                    :href="route('admin.providers')"
                    class="inline-flex text-xs text-cyan-300 hover:underline"
                >
                    Manage platform providers →
                </Link>
            </section>

            <section class="md-card space-y-4 p-5">
                <h2 class="text-sm font-medium text-white">Subscription</h2>
                <div v-if="subscription" class="space-y-3 text-sm">
                    <div class="flex justify-between gap-3">
                        <span class="text-zinc-500">Plan</span>
                        <span class="text-zinc-200"
                            >{{ subscription.plan }} ·
                            {{ subscription.product }}</span
                        >
                    </div>
                    <div class="flex justify-between gap-3">
                        <span class="text-zinc-500">Price</span>
                        <span class="tabular-nums text-zinc-200"
                            >${{ subscription.price }} / mo</span
                        >
                    </div>
                    <div class="flex justify-between gap-3">
                        <span class="text-zinc-500">Renews</span>
                        <span class="text-zinc-200">{{
                            subscription.renews
                        }}</span>
                    </div>
                    <div class="flex justify-between gap-3">
                        <span class="text-zinc-500">Status</span>
                        <StatusBadge :status="subscription.status" />
                    </div>
                </div>
                <p v-else class="text-sm text-zinc-500">No paid subscription.</p>
                <Link
                    :href="route('admin.subscriptions')"
                    class="inline-flex text-xs text-cyan-300 hover:underline"
                >
                    Manage subscriptions →
                </Link>
            </section>

            <section class="md-card space-y-4 p-5 lg:col-span-1">
                <div class="flex items-center justify-between gap-3">
                    <h2 class="text-sm font-medium text-white">Hosts</h2>
                    <button
                        type="button"
                        class="text-xs text-cyan-300 hover:underline"
                        @click="openSubdomainModal"
                    >
                        Edit
                    </button>
                </div>
                <ul class="space-y-2">
                    <li
                        v-for="h in hosts"
                        :key="h.id"
                        class="flex items-center justify-between rounded-lg border border-zinc-800 px-3 py-2.5"
                    >
                        <div class="min-w-0">
                            <div class="truncate font-mono text-xs text-zinc-200">
                                {{ h.host }}
                            </div>
                            <div class="text-[11px] text-zinc-500">
                                {{ h.custom ? 'Custom domain' : 'Platform subdomain' }}
                                · SSL {{ h.ssl ? 'on' : 'off' }}
                            </div>
                        </div>
                        <StatusBadge :status="h.status" />
                    </li>
                    <li
                        v-if="!hosts.length"
                        class="text-sm text-zinc-500"
                    >
                        No hosts provisioned.
                    </li>
                </ul>
            </section>
        </div>

        <AccountUsersPanel :account-id="props.id" />

        <Modal
            :show="showProvider"
            title="Mail provider"
            description="Choose which delivery backend this tenant uses."
            @close="showProvider = false"
        >
            <div class="space-y-2">
                <label
                    v-for="p in activeProviders"
                    :key="p.id"
                    class="flex cursor-pointer items-start gap-3 rounded-xl border px-3 py-3 transition"
                    :class="
                        providerId === p.id
                            ? 'border-cyan-400/40 bg-cyan-400/10'
                            : 'border-zinc-800 hover:border-zinc-700'
                    "
                >
                    <input
                        v-model="providerId"
                        type="radio"
                        class="mt-1"
                        :value="p.id"
                    />
                    <span class="min-w-0">
                        <span class="block text-sm font-medium text-white">
                            {{ p.name }}
                            <span
                                v-if="p.default"
                                class="ml-1 text-[11px] font-normal text-amber-300"
                                >Default</span
                            >
                        </span>
                        <span class="mt-0.5 block text-xs text-zinc-500">
                            {{ p.description }}
                        </span>
                    </span>
                </label>
                <p
                    v-if="!activeProviders.length"
                    class="text-sm text-zinc-500"
                >
                    No active providers. Enable one under Providers first.
                </p>
            </div>
            <template #footer>
                <button
                    type="button"
                    class="md-btn-ghost"
                    @click="showProvider = false"
                >
                    Cancel
                </button>
                <button
                    type="button"
                    class="md-btn-solid"
                    @click="saveProvider"
                >
                    <Check :size="14" />
                    Save
                </button>
            </template>
        </Modal>

        <Modal
            :show="showSubdomain"
            title="Custom subdomain"
            description="Assign a platform subdomain or custom host for this account."
            @close="showSubdomain = false"
        >
            <div class="space-y-3">
                <div>
                    <label class="mb-1.5 block text-xs text-zinc-500"
                        >Platform subdomain</label
                    >
                    <div class="flex items-center gap-2">
                        <input
                            v-model="subdomainForm.subdomain"
                            class="md-input"
                            placeholder="acme"
                        />
                        <span class="shrink-0 text-sm text-zinc-500"
                            >.maildesk.test</span
                        >
                    </div>
                </div>
                <div>
                    <label class="mb-1.5 block text-xs text-zinc-500"
                        >Custom domain (optional)</label
                    >
                    <input
                        v-model="subdomainForm.customHost"
                        class="md-input"
                        placeholder="mail.customer.com"
                    />
                </div>
            </div>
            <template #footer>
                <button
                    type="button"
                    class="md-btn-ghost"
                    @click="showSubdomain = false"
                >
                    Cancel
                </button>
                <button
                    type="button"
                    class="md-btn-solid"
                    @click="saveSubdomain"
                >
                    <Check :size="14" />
                    Save
                </button>
            </template>
        </Modal>

        <DeleteAccountPanel :account="account" />
    </AdminLayout>
</template>
