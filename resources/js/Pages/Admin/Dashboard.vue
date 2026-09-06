<script setup>
import { computed, watchEffect } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import { usePlatform } from '@/composables/usePlatform';
import {
    Building2,
    CreditCard,
    DollarSign,
    Globe2,
    Server,
    TrendingUp,
} from '@lucide/vue';

const props = defineProps({
    stats: { type: Object, default: () => ({}) },
    accounts: { type: Array, default: () => [] },
    subscriptions: { type: Array, default: () => [] },
    providers: { type: Array, default: () => [] },
    pendingHosts: { type: Array, default: () => [] },
    orphanedCount: { type: Number, default: 0 },
});

const { hydrate, stats, accounts, providers, subscriptions } = usePlatform();

watchEffect(() => {
    hydrate({
        stats: props.stats,
        accounts: props.accounts,
        providers: props.providers,
        subscriptions: props.subscriptions,
    });
});

const cards = computed(() => [
    {
        label: 'Accounts',
        value: Number(stats.value.accounts || 0).toLocaleString(),
        icon: Building2,
        href: 'admin.accounts',
    },
    {
        label: 'Active subscriptions',
        value: Number(stats.value.activeSubscriptions || 0).toLocaleString(),
        icon: CreditCard,
        href: 'admin.subscriptions',
    },
    {
        label: 'MRR',
        value: `$${Number(stats.value.mrr || 0).toLocaleString()}`,
        icon: DollarSign,
        href: 'admin.plans',
    },
    {
        label: 'Mail providers',
        value: String(
            providers.value.filter((p) => p.status === 'active').length,
        ),
        icon: Server,
        href: 'admin.providers',
    },
]);

const recentAccounts = computed(() => accounts.value.slice(0, 5));
const recentSubs = computed(() => subscriptions.value.slice(0, 5));
const pendingHosts = computed(() => props.pendingHosts);
const orphanedCount = computed(() => props.orphanedCount);
const trialCount = computed(
    () =>
        Number(stats.value.trialAccounts) ||
        accounts.value.filter((a) => a.status === 'trial').length,
);
</script>

<template>
    <Head title="Admin · Overview" />

    <AdminLayout>
        <PageHeader
            title="Overview"
            description="Central SaaS control for tenants, plans, hosts, and mail providers."
        />

        <div class="mb-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <Link
                v-for="card in cards"
                :key="card.label"
                :href="route(card.href)"
                class="md-card group p-5 transition hover:border-cyan-400/40"
            >
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <div
                            class="text-xs uppercase tracking-wide text-zinc-500"
                        >
                            {{ card.label }}
                        </div>
                        <div
                            class="mt-2 text-2xl font-semibold tabular-nums text-white"
                        >
                            {{ card.value }}
                        </div>
                    </div>
                    <span
                        class="flex h-9 w-9 items-center justify-center rounded-lg bg-cyan-400/10 text-cyan-300 transition group-hover:scale-105"
                    >
                        <component :is="card.icon" :size="16" />
                    </span>
                </div>
            </Link>
        </div>

        <div class="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="md-card flex items-center gap-3 p-4">
                <TrendingUp :size="16" class="text-emerald-400" />
                <div>
                    <div class="text-xs text-zinc-500">Trials</div>
                    <div class="text-sm font-medium text-white">
                        {{ trialCount }}
                    </div>
                </div>
            </div>
            <div class="md-card flex items-center gap-3 p-4">
                <CreditCard :size="16" class="text-amber-400" />
                <div>
                    <div class="text-xs text-zinc-500">30d churn</div>
                    <div class="text-sm font-medium text-white">
                        {{ stats.churn30d }}
                    </div>
                </div>
            </div>
            <div class="md-card flex items-center gap-3 p-4">
                <Globe2 :size="16" class="text-sky-400" />
                <div>
                    <div class="text-xs text-zinc-500">
                        Custom hosts · {{ stats.customSubdomains }} total
                    </div>
                    <div class="text-sm font-medium text-white">
                        {{ pendingHosts.length }} pending DNS
                    </div>
                </div>
            </div>
            <Link
                :href="route('admin.accounts')"
                class="md-card flex items-center gap-3 p-4 transition hover:border-rose-500/40"
            >
                <Server :size="16" class="text-rose-400" />
                <div>
                    <div class="text-xs text-zinc-500">Orphaned providers</div>
                    <div class="text-sm font-medium text-white">
                        {{ orphanedCount }} tenants
                    </div>
                </div>
            </Link>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <section class="md-card overflow-hidden">
                <div
                    class="flex items-center justify-between border-b border-zinc-800 px-5 py-3.5"
                >
                    <h2 class="text-sm font-medium text-white">
                        Recent accounts
                    </h2>
                    <Link
                        :href="route('admin.accounts')"
                        class="text-xs text-cyan-300 hover:underline"
                    >
                        View all
                    </Link>
                </div>
                <ul class="divide-y divide-zinc-900">
                    <li
                        v-for="a in recentAccounts"
                        :key="a.id"
                    >
                        <Link
                            :href="route('admin.accounts.show', a.id)"
                            class="flex items-center justify-between gap-3 px-5 py-3 transition hover:bg-zinc-900/50"
                        >
                            <div class="min-w-0">
                                <div class="truncate text-sm text-white">
                                    {{ a.name }}
                                </div>
                                <div class="truncate text-xs text-zinc-500">
                                    {{ a.email }}
                                </div>
                            </div>
                            <div class="shrink-0 text-right">
                                <div class="text-xs text-zinc-300">
                                    {{ a.plan }}
                                </div>
                                <div class="text-[11px] capitalize text-zinc-500">
                                    {{ a.status.replace('_', ' ') }}
                                </div>
                            </div>
                        </Link>
                    </li>
                </ul>
            </section>

            <section class="md-card overflow-hidden">
                <div
                    class="flex items-center justify-between border-b border-zinc-800 px-5 py-3.5"
                >
                    <h2 class="text-sm font-medium text-white">
                        Subscriptions
                    </h2>
                    <Link
                        :href="route('admin.subscriptions')"
                        class="text-xs text-cyan-300 hover:underline"
                    >
                        View all
                    </Link>
                </div>
                <ul class="divide-y divide-zinc-900">
                    <li
                        v-for="s in recentSubs"
                        :key="s.id"
                        class="flex items-center justify-between gap-3 px-5 py-3"
                    >
                        <div class="min-w-0">
                            <div class="truncate text-sm text-white">
                                {{ s.account }}
                            </div>
                            <div class="truncate text-xs capitalize text-zinc-500">
                                {{ s.product }} · {{ s.plan }}
                            </div>
                        </div>
                        <div class="shrink-0 text-right">
                            <div class="text-sm tabular-nums text-zinc-200">
                                ${{ s.price }}/mo
                            </div>
                            <div class="text-[11px] capitalize text-zinc-500">
                                {{ s.status.replace('_', ' ') }}
                            </div>
                        </div>
                    </li>
                </ul>
            </section>
        </div>
    </AdminLayout>
</template>
