<script setup>
import { computed, ref, watch, watchEffect } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import EmptyState from '@/Components/EmptyState.vue';
import { providerHealthForAccount } from '@/lib/mailProviders';
import { usePlatform } from '@/composables/usePlatform';
import { Building2, ChevronLeft, ChevronRight, Search } from '@lucide/vue';

const props = defineProps({
    accounts: { type: Array, default: () => [] },
    pagination: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
    stats: { type: Object, default: () => ({}) },
    providers: { type: Array, default: () => [] },
});

const { accounts, providers, findProvider, hydrate } = usePlatform();
const baseDomain = computed(() => usePage().props.tenant?.base_domain || 'maildesk.ng');

watchEffect(() => {
    hydrate({
        accounts: props.accounts,
        providers: props.providers,
    });
});

const search = ref(props.filters.search || '');
const statusFilter = ref(props.filters.status || 'all');
const isFiltering = ref(false);

let debounceTimeout = null;

const applyFilters = (page = 1) => {
    isFiltering.value = true;
    const params = { page };
    if (search.value.trim()) {
        params.search = search.value.trim();
    }
    if (statusFilter.value && statusFilter.value !== 'all') {
        params.status = statusFilter.value;
    }
    router.get(route('admin.accounts'), params, {
        preserveState: true,
        preserveScroll: true,
        only: ['accounts', 'pagination', 'filters', 'stats'],
        onFinish: () => {
            isFiltering.value = false;
        },
    });
};

watch(search, () => {
    clearTimeout(debounceTimeout);
    debounceTimeout = setTimeout(() => applyFilters(1), 300);
});

watch(statusFilter, () => {
    applyFilters(1);
});

const goToPage = (page) => {
    if (page < 1 || page > props.pagination.last_page) {
        return;
    }
    applyFilters(page);
};

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
        >
            <template #actions>
                <div class="flex gap-3 text-xs text-zinc-500">
                    <span>{{ stats.total }} total</span>
                    <span>{{ stats.active }} active</span>
                    <span>{{ stats.trial }} trial</span>
                </div>
            </template>
        </PageHeader>

        <div class="mb-4 flex flex-col gap-3 lg:flex-row lg:items-center">
            <div class="relative min-w-0 flex-1">
                <Search
                    :size="15"
                    class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-zinc-500"
                />
                <input
                    v-model="search"
                    class="md-input pl-9"
                    placeholder="Search accounts, owners, emails…"
                />
            </div>
            <select v-model="statusFilter" class="md-input w-full lg:w-40">
                <option value="all">All statuses</option>
                <option value="active">Active</option>
                <option value="trial">Trial</option>
                <option value="past_due">Past due</option>
                <option value="suspended">Suspended</option>
            </select>
        </div>

        <EmptyState
            v-if="!accounts.length"
            title="No accounts found"
            description="Try a different search or filter."
        >
            <template #icon>
                <Building2 :size="28" :stroke-width="1.5" />
            </template>
        </EmptyState>

        <template v-else>
            <div class="md-table-wrap" :class="{ 'opacity-50': isFiltering }">
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
                            v-for="a in accounts"
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
                                {{ a.subdomain }}.{{ baseDomain }}
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

            <div
                v-if="pagination.last_page > 1"
                class="mt-4 flex items-center justify-between border-t border-zinc-800 pt-4"
            >
                <div class="text-sm text-zinc-500">
                    Showing {{ pagination.from }}–{{ pagination.to }} of {{ pagination.total }}
                </div>
                <div class="flex gap-1">
                    <button
                        type="button"
                        class="rounded-lg border border-zinc-700 px-3 py-1.5 text-sm text-zinc-400 transition hover:border-zinc-500 hover:text-white disabled:opacity-40 disabled:cursor-not-allowed"
                        :disabled="pagination.current_page === 1"
                        @click="goToPage(pagination.current_page - 1)"
                    >
                        <ChevronLeft :size="16" />
                    </button>
                    <span class="px-3 py-1.5 text-sm text-zinc-400">
                        Page {{ pagination.current_page }} of {{ pagination.last_page }}
                    </span>
                    <button
                        type="button"
                        class="rounded-lg border border-zinc-700 px-3 py-1.5 text-sm text-zinc-400 transition hover:border-zinc-500 hover:text-white disabled:opacity-40 disabled:cursor-not-allowed"
                        :disabled="pagination.current_page === pagination.last_page"
                        @click="goToPage(pagination.current_page + 1)"
                    >
                        <ChevronRight :size="16" />
                    </button>
                </div>
            </div>
        </template>
    </AdminLayout>
</template>
