<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import EmptyState from '@/Components/EmptyState.vue';
import { DollarSign, Receipt } from '@lucide/vue';

const props = defineProps({
    summary: { type: Object, required: true },
    breakdown: { type: Array, default: () => [] },
    paymentsAvailable: { type: Boolean, default: false },
    payments: { type: Array, default: () => [] },
    monthly: { type: Array, default: () => [] },
});

const usd = (n) =>
    `$${Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 })}`;

const cards = computed(() => [
    { label: 'MRR', value: usd(props.summary.mrr) },
    { label: 'ARR (MRR × 12)', value: usd(props.summary.arr) },
    { label: 'Paid subscriptions', value: props.summary.paidSubscriptions },
    { label: 'Paying accounts', value: props.summary.payingAccounts },
]);

const formatDate = (iso) => (iso ? new Date(iso).toLocaleDateString() : '—');
</script>

<template>
    <Head title="Admin · Revenue" />

    <AdminLayout>
        <PageHeader
            title="Revenue"
            description="Monthly recurring revenue from active paid subscriptions (yearly plans ÷ 12), plus fulfilled Monipay receipts."
        />

        <div class="mb-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <div v-for="c in cards" :key="c.label" class="md-card p-5">
                <div class="text-xs uppercase tracking-wide text-zinc-500">{{ c.label }}</div>
                <div class="mt-2 text-2xl font-semibold tabular-nums text-white">{{ c.value }}</div>
            </div>
        </div>

        <section class="md-card mb-6 overflow-hidden">
            <div class="border-b border-zinc-800 px-5 py-3.5">
                <h2 class="text-sm font-medium text-white">MRR by plan</h2>
            </div>
            <p v-if="!breakdown.length" class="px-5 py-6 text-sm text-zinc-500">
                No active paid subscriptions yet.
            </p>
            <table v-else class="min-w-full text-left text-sm">
                <thead class="border-b border-zinc-800 text-xs uppercase tracking-wide text-zinc-500">
                    <tr>
                        <th class="px-4 py-3 font-medium">Plan</th>
                        <th class="px-4 py-3 font-medium">Product</th>
                        <th class="px-4 py-3 font-medium">List price</th>
                        <th class="px-4 py-3 font-medium">Subscriptions</th>
                        <th class="px-4 py-3 text-right font-medium">MRR</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-900">
                    <tr v-for="row in breakdown" :key="row.planKey || row.plan">
                        <td class="px-4 py-3 text-white">{{ row.plan }}</td>
                        <td class="px-4 py-3 capitalize text-zinc-400">{{ row.product }}</td>
                        <td class="px-4 py-3 tabular-nums text-zinc-300">
                            {{ usd(row.price) }}/{{ row.interval === 'year' ? 'yr' : 'mo' }}
                        </td>
                        <td class="px-4 py-3 tabular-nums text-zinc-300">{{ row.subscriptions }}</td>
                        <td class="px-4 py-3 text-right tabular-nums text-white">{{ usd(row.mrr) }}</td>
                    </tr>
                </tbody>
            </table>
        </section>

        <template v-if="paymentsAvailable">
            <section class="md-card mb-6 overflow-hidden">
                <div class="border-b border-zinc-800 px-5 py-3.5">
                    <h2 class="text-sm font-medium text-white">Collected per month (Monipay, NGN)</h2>
                </div>
                <p v-if="!monthly.length" class="px-5 py-6 text-sm text-zinc-500">No fulfilled payments yet.</p>
                <ul v-else class="divide-y divide-zinc-900">
                    <li v-for="m in monthly" :key="m.month" class="flex items-center justify-between px-5 py-3 text-sm">
                        <span class="text-zinc-300">{{ m.label }} · {{ m.count }} payment(s)</span>
                        <span class="tabular-nums text-white">{{ m.totalFormatted }}</span>
                    </li>
                </ul>
            </section>

            <EmptyState
                v-if="!payments.length"
                title="No receipts yet"
                description="Fulfilled Monipay payments appear here."
            >
                <template #icon>
                    <Receipt :size="28" :stroke-width="1.5" />
                </template>
            </EmptyState>
            <div v-else class="md-table-wrap">
                <table class="min-w-full text-left text-sm">
                    <thead class="border-b border-zinc-800 text-xs uppercase tracking-wide text-zinc-500">
                        <tr>
                            <th class="px-4 py-3 font-medium">Paid</th>
                            <th class="px-4 py-3 font-medium">Account</th>
                            <th class="px-4 py-3 font-medium">Plan</th>
                            <th class="px-4 py-3 font-medium">Reference</th>
                            <th class="px-4 py-3 text-right font-medium">Amount</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-900">
                        <tr v-for="p in payments" :key="p.id">
                            <td class="px-4 py-3 text-zinc-400">{{ formatDate(p.paidAt) }}</td>
                            <td class="px-4 py-3">
                                <Link
                                    v-if="p.accountId"
                                    :href="route('admin.accounts.show', p.accountId)"
                                    class="text-white hover:text-cyan-300"
                                    >{{ p.account }}</Link
                                >
                                <span v-else class="text-zinc-500">{{ p.account || 'Deleted account' }}</span>
                            </td>
                            <td class="px-4 py-3 text-zinc-300">{{ p.plan }}</td>
                            <td class="px-4 py-3 font-mono text-xs text-zinc-500">{{ p.reference }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-white">{{ p.amountFormatted }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>
        <p v-else class="text-sm text-zinc-500">
            <DollarSign :size="14" class="inline" /> Payment records are not available.
        </p>
    </AdminLayout>
</template>
