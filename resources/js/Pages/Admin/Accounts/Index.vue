<script setup>
import { computed, ref, watchEffect } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import EmptyState from '@/Components/EmptyState.vue';
import { providerHealthForAccount } from '@/lib/mailProviders';
import { usePlatform } from '@/composables/usePlatform';
import { Building2, Search } from '@lucide/vue';

const props = defineProps({
    accounts: { type: Array, default: () => [] },
    providers: { type: Array, default: () => [] },
});

const { accounts, providers, findProvider, hydrate } = usePlatform();

watchEffect(() => {
    hydrate({
        accounts: props.accounts,
        providers: props.providers,
    });
});

const search = ref('');
const status = ref('all');
const plan = ref('all');
const providerFilter = ref('all');

const filtered = computed(() => {
    const q = search.value.trim().toLowerCase();
    return accounts.value.filter((a) => {
        const statusOk = status.value === 'all' || a.status === status.value;
        const planOk = plan.value === 'all' || a.plan === plan.value;
        const health = providerHealthForAccount(a, providers.value);
        const providerOk =
            providerFilter.value === 'all' ||
            (providerFilter.value === 'orphaned' &&
                health.reason === 'orphaned') ||
            a.provider === providerFilter.value;
        const searchOk =
            !q ||
            a.name.toLowerCase().includes(q) ||
            (a.email || '').toLowerCase().includes(q) ||
            (a.owner || '').toLowerCase().includes(q) ||
            (a.subdomain || '').toLowerCase().includes(q) ||
            (a.provider || '').toLowerCase().includes(q);
        return statusOk && planOk && providerOk && searchOk;
    });
});

const providerLabel = (a) => {
    const p = findProvider(a.provider);
    if (p) return p.name;
    if (a.provider) return `${a.provider} (missing)`;
    return 'Unassigned';
};

const providerTone = (a) => {
    const health = providerHealthForAccount(a, providers.value);
    if (health.ok) return 'text-zinc-400';
    return 'text-rose-300';
};

const goShow = (a) => router.visit(route('admin.accounts.show', a.id));
</script>

<template>
    <Head title="Admin · Accounts" />

    <AdminLayout>
        <PageHeader
            title="Accounts"
            description="Every workspace on the MailDesk platform."
        />

        <div class="mb-4 flex flex-col gap-3 lg:flex-row lg:items-center">
            <div class="relative min-w-0 flex-1">
                <Search
                    :size="15"
                    class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-zinc-500"
                />
                <input
                    v-model="search"
                    class="md-input pl-9"
                    placeholder="Search accounts, owners, subdomains…"
                />
            </div>
            <select v-model="status" class="md-input w-full lg:w-40">
                <option value="all">All statuses</option>
                <option value="active">Active</option>
                <option value="trial">Trial</option>
                <option value="past_due">Past due</option>
                <option value="suspended">Suspended</option>
            </select>
            <select v-model="plan" class="md-input w-full lg:w-36">
                <option value="all">All plans</option>
                <option value="Starter">Starter</option>
                <option value="Pro">Pro</option>
                <option value="Enterprise">Enterprise</option>
            </select>
            <select v-model="providerFilter" class="md-input w-full lg:w-48">
                <option value="all">All providers</option>
                <option value="orphaned">Orphaned only</option>
                <option
                    v-for="p in providers"
                    :key="p.id"
                    :value="p.id"
                >
                    {{ p.name }}
                </option>
            </select>
        </div>

        <EmptyState
            v-if="!filtered.length"
            title="No accounts found"
            description="Try a different search or filter."
        >
            <template #icon>
                <Building2 :size="28" :stroke-width="1.5" />
            </template>
        </EmptyState>

        <div v-else class="md-table-wrap">
            <table class="min-w-full text-left text-sm">
                <thead
                    class="border-b border-zinc-800 text-xs uppercase tracking-wide text-zinc-500"
                >
                    <tr>
                        <th class="px-4 py-3 font-medium">Account</th>
                        <th class="px-4 py-3 font-medium">Plan</th>
                        <th class="px-4 py-3 font-medium">Provider</th>
                        <th class="px-4 py-3 font-medium">Subdomain</th>
                        <th class="px-4 py-3 font-medium">MRR</th>
                        <th class="px-4 py-3 font-medium">Status</th>
                        <th class="px-4 py-3 font-medium">Created</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-900">
                    <tr
                        v-for="a in filtered"
                        :key="a.id"
                        class="cursor-pointer transition hover:bg-zinc-900/50"
                        @click="goShow(a)"
                    >
                        <td class="px-4 py-3">
                            <Link
                                :href="route('admin.accounts.show', a.id)"
                                class="font-medium text-white hover:text-cyan-300"
                                @click.stop
                            >
                                {{ a.name }}
                            </Link>
                            <div class="text-xs text-zinc-500">
                                {{ a.owner }} · {{ a.email }}
                            </div>
                        </td>
                        <td class="px-4 py-3 text-zinc-300">
                            {{ a.plan }}
                            <span
                                class="block text-[11px] capitalize text-zinc-500"
                            >
                                {{ a.product }}
                            </span>
                        </td>
                        <td class="px-4 py-3" :class="providerTone(a)">
                            {{ providerLabel(a) }}
                        </td>
                        <td class="px-4 py-3 font-mono text-xs text-zinc-400">
                            {{ a.subdomain }}.maildesk.test
                        </td>
                        <td class="px-4 py-3 tabular-nums text-zinc-300">
                            ${{ a.mrr }}
                        </td>
                        <td class="px-4 py-3">
                            <StatusBadge :status="a.status" />
                        </td>
                        <td class="px-4 py-3 text-zinc-500">{{ a.created }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AdminLayout>
</template>
