<script setup>
import { computed, ref, watchEffect } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import EmptyState from '@/Components/EmptyState.vue';
import { usePlatform } from '@/composables/usePlatform';
import { useToast } from '@/composables/useToast';
import { CreditCard, Search } from '@lucide/vue';

const props = defineProps({
    subscriptions: { type: Array, default: () => [] },
    plans: { type: Array, default: () => [] },
});

const search = ref('');
const status = ref('all');
const product = ref('all');
const toast = useToast();
const { subscriptions, hydrate } = usePlatform();

watchEffect(() => {
    hydrate({ subscriptions: props.subscriptions });
});

const rows = subscriptions;

const filtered = computed(() => {
    const q = search.value.trim().toLowerCase();
    return rows.value.filter((s) => {
        const statusOk = status.value === 'all' || s.status === status.value;
        const productOk =
            product.value === 'all' || s.product === product.value;
        const searchOk =
            !q ||
            s.account.toLowerCase().includes(q) ||
            s.plan.toLowerCase().includes(q);
        return statusOk && productOk && searchOk;
    });
});

const totalMrr = computed(() =>
    filtered.value
        .filter((s) => s.status === 'active')
        .reduce((sum, s) => sum + s.price, 0),
);

const plansFor = (sub) =>
    props.plans.filter((p) => p.product === sub.product);

const updateStatus = (sub, next) => {
    router.put(
        route('admin.subscriptions.update', sub.dbId),
        { status: next },
        {
            preserveScroll: true,
            onSuccess: () => toast.success('Subscription status updated.'),
            onError: () => toast.error('Could not update subscription.'),
        },
    );
};

const updatePlan = (sub, planDbId) => {
    router.put(
        route('admin.subscriptions.update', sub.dbId),
        { plan_id: Number(planDbId) },
        {
            preserveScroll: true,
            onSuccess: () => toast.success('Subscription plan updated.'),
            onError: () => toast.error('Could not update plan.'),
        },
    );
};
</script>

<template>
    <Head title="Admin · Subscriptions" />

    <AdminLayout>
        <PageHeader
            title="Subscriptions"
            description="Live subscriptions across transactional and marketing products."
        >
            <template #actions>
                <Link :href="route('admin.plans')" class="md-btn-solid">
                    Edit plan catalog
                </Link>
            </template>
        </PageHeader>

        <div
            class="mb-4 flex flex-wrap items-center justify-between gap-3 text-sm"
        >
            <div class="text-zinc-500">
                Showing
                <span class="text-zinc-300">{{ filtered.length }}</span>
                · Active MRR
                <span class="font-medium tabular-nums text-white"
                    >${{ totalMrr.toLocaleString() }}</span
                >
            </div>
        </div>

        <div class="mb-4 flex flex-col gap-3 lg:flex-row lg:items-center">
            <div class="relative min-w-0 flex-1">
                <Search
                    :size="15"
                    class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-zinc-500"
                />
                <input
                    v-model="search"
                    class="md-input pl-9"
                    placeholder="Search accounts or plans…"
                />
            </div>
            <select v-model="product" class="md-input w-full lg:w-44">
                <option value="all">All products</option>
                <option value="transactional">Transactional</option>
                <option value="marketing">Marketing</option>
            </select>
            <select v-model="status" class="md-input w-full lg:w-40">
                <option value="all">All statuses</option>
                <option value="active">Active</option>
                <option value="past_due">Past due</option>
                <option value="trial">Trial</option>
            </select>
        </div>

        <EmptyState
            v-if="!filtered.length"
            title="No subscriptions"
            description="Try clearing filters."
        >
            <template #icon>
                <CreditCard :size="28" :stroke-width="1.5" />
            </template>
        </EmptyState>

        <div v-else class="md-table-wrap">
            <table class="min-w-full text-left text-sm">
                <thead
                    class="border-b border-zinc-800 text-xs uppercase tracking-wide text-zinc-500"
                >
                    <tr>
                        <th class="px-4 py-3 font-medium">Account</th>
                        <th class="px-4 py-3 font-medium">Product</th>
                        <th class="px-4 py-3 font-medium">Plan</th>
                        <th class="px-4 py-3 font-medium">Price</th>
                        <th class="px-4 py-3 font-medium">Seats</th>
                        <th class="px-4 py-3 font-medium">Renews</th>
                        <th class="px-4 py-3 font-medium">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-900">
                    <tr
                        v-for="s in filtered"
                        :key="s.id"
                        class="transition hover:bg-zinc-900/40"
                    >
                        <td class="px-4 py-3">
                            <Link
                                :href="
                                    route('admin.accounts.show', s.accountId)
                                "
                                class="font-medium text-white hover:text-cyan-300"
                            >
                                {{ s.account }}
                            </Link>
                        </td>
                        <td class="px-4 py-3 capitalize text-zinc-400">
                            {{ s.product }}
                        </td>
                        <td class="px-4 py-3 text-zinc-300">
                            <select
                                class="md-input !w-auto !py-1"
                                :value="
                                    plans.find((p) => p.id === s.planId)?.dbId ||
                                    ''
                                "
                                @change="updatePlan(s, $event.target.value)"
                            >
                                <option
                                    v-for="p in plansFor(s)"
                                    :key="p.dbId"
                                    :value="p.dbId"
                                >
                                    {{ p.name }}
                                </option>
                            </select>
                        </td>
                        <td class="px-4 py-3 tabular-nums text-zinc-300">
                            ${{ s.price }}/mo
                        </td>
                        <td class="px-4 py-3 tabular-nums text-zinc-400">
                            {{ s.seats }}
                        </td>
                        <td class="px-4 py-3 text-zinc-500">{{ s.renews }}</td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                <StatusBadge :status="s.status" />
                                <select
                                    class="md-input !w-auto !py-1"
                                    :value="s.status"
                                    @change="
                                        updateStatus(s, $event.target.value)
                                    "
                                >
                                    <option value="active">active</option>
                                    <option value="past_due">past_due</option>
                                    <option value="trial">trial</option>
                                    <option value="canceled">canceled</option>
                                </select>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AdminLayout>
</template>
