<script setup>
import { computed, onMounted } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useToast } from '@/composables/useToast';
import { usePlansModal } from '@/composables/usePlansModal';

// Payment history after Monipay checkout. Plan selection lives in PlansModal
// so Upgrade goes straight to the gateway (no intermediate billing step).
const props = defineProps({
    payments: {
        type: Object,
        default: null,
    },
});

const page = usePage();
const toast = useToast();
const { open: openPlans } = usePlansModal();

const configured = computed(() => Boolean(props.payments?.configured));
const history = computed(() => props.payments?.payments || []);

const statusClass = (status) =>
    ({
        paid: 'bg-emerald-500/15 text-emerald-400',
        pending: 'bg-amber-500/15 text-amber-300',
        failed: 'bg-rose-500/15 text-rose-400',
        abandoned: 'bg-zinc-500/20 text-zinc-400',
    })[status] || 'bg-zinc-500/20 text-zinc-300';

onMounted(() => {
    // Result of the Monipay callback redirect.
    const flash = page.props.flash || {};
    if (flash.success) toast.success(flash.success, 6000);
    if (flash.error) toast.error(flash.error, 8000);
});
</script>

<template>
    <section id="monipay-upgrade" class="md-card overflow-hidden">
        <div
            class="flex flex-wrap items-center justify-between gap-3 border-b border-zinc-800 px-5 py-3.5"
        >
            <div>
                <h3 class="text-sm font-medium text-white">Payments</h3>
                <p class="mt-0.5 text-xs text-zinc-500">
                    Choose a plan to pay with Monipay (card or bank transfer).
                    Each payment covers one billing period; renew manually.
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <span
                    v-if="!configured"
                    class="rounded-full bg-zinc-800 px-2 py-0.5 text-[11px] text-zinc-400"
                    >Payments not configured</span
                >
                <button
                    type="button"
                    class="md-btn-solid"
                    :disabled="!configured"
                    @click="openPlans('transactional')"
                >
                    View plans
                </button>
            </div>
        </div>

        <div class="px-5 py-3.5">
            <h4 class="text-xs font-medium uppercase tracking-wide text-zinc-500">
                Payment history
            </h4>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="text-xs text-zinc-500">
                    <tr>
                        <th class="px-5 py-2 font-normal">Date</th>
                        <th class="px-5 py-2 font-normal">Plan</th>
                        <th class="px-5 py-2 font-normal">Amount</th>
                        <th class="px-5 py-2 font-normal">Status</th>
                        <th class="px-5 py-2 font-normal">Reference</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-900">
                    <tr v-for="p in history" :key="p.id">
                        <td class="px-5 py-2 text-zinc-300">{{ p.date }}</td>
                        <td class="px-5 py-2 text-zinc-300">{{ p.plan }}</td>
                        <td class="px-5 py-2 tabular-nums text-zinc-300">
                            {{ p.amount_formatted }}
                        </td>
                        <td class="px-5 py-2">
                            <span
                                class="rounded-full px-2 py-0.5 text-[11px] font-medium capitalize"
                                :class="statusClass(p.status)"
                                >{{ p.status }}</span
                            >
                        </td>
                        <td class="px-5 py-2 font-mono text-xs text-zinc-500">
                            {{ p.reference }}
                        </td>
                    </tr>
                    <tr v-if="!history.length">
                        <td
                            colspan="5"
                            class="px-5 py-6 text-center text-sm text-zinc-500"
                        >
                            No payments yet.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
