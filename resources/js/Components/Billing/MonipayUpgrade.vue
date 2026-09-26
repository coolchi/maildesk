<script setup>
import { computed, onMounted, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { useToast } from '@/composables/useToast';

// Plan upgrades via Monipay hosted checkout. The browser only ever talks to
// our backend; the backend initializes the payment and we redirect to the
// returned authorization_url. No keys are needed (or present) here.
const props = defineProps({
    payments: {
        type: Object,
        default: null,
    },
});

const page = usePage();
const toast = useToast();
const busy = ref(null);

const configured = computed(() => Boolean(props.payments?.configured));
const canManage = computed(() => Boolean(props.payments?.can_manage));
const plans = computed(() => props.payments?.plans || []);
const history = computed(() => props.payments?.payments || []);

const statusClass = (status) =>
    ({
        paid: 'bg-emerald-500/15 text-emerald-400',
        pending: 'bg-amber-500/15 text-amber-300',
        failed: 'bg-rose-500/15 text-rose-400',
        abandoned: 'bg-zinc-500/20 text-zinc-400',
    })[status] || 'bg-zinc-500/20 text-zinc-300';

const disabledReason = (plan) => {
    if (!configured.value) return 'Payments not configured';
    if (!canManage.value) return 'Only owners and admins can pay';
    if (!plan.payable) return 'Below the ₦50 minimum';
    return null;
};

const pay = (plan) => {
    if (disabledReason(plan) || busy.value) return;
    busy.value = plan.key;
    router.post(
        route('billing.monipay.initialize'),
        { plan: plan.key },
        {
            preserveScroll: true,
            onError: (errors) =>
                toast.error(errors.plan || 'Could not start the payment.'),
            onFinish: () => {
                busy.value = null;
            },
        },
    );
};

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
                <h3 class="text-sm font-medium text-white">Upgrade plan</h3>
                <p class="mt-0.5 text-xs text-zinc-500">
                    Pay securely in naira with Monipay (card, bank transfer).
                    Each payment covers one billing period; renew manually.
                </p>
            </div>
            <span
                v-if="!configured"
                class="rounded-full bg-zinc-800 px-2 py-0.5 text-[11px] text-zinc-400"
                >Payments not configured</span
            >
        </div>

        <ul class="divide-y divide-zinc-900">
            <li
                v-for="plan in plans"
                :key="plan.key"
                class="flex flex-wrap items-center gap-3 px-5 py-3"
            >
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-sm text-white">{{ plan.name }}</span>
                        <span
                            class="rounded-full bg-zinc-800 px-2 py-0.5 text-[11px] capitalize text-zinc-400"
                            >{{ plan.product }}</span
                        >
                        <span
                            v-if="plan.current"
                            class="rounded-full bg-emerald-500/15 px-2 py-0.5 text-[11px] font-medium text-emerald-400"
                            >Current</span
                        >
                    </div>
                </div>
                <span class="text-sm tabular-nums text-zinc-300"
                    >{{ plan.amount_formatted }} / {{ plan.interval }}</span
                >
                <button
                    type="button"
                    class="md-btn-solid shrink-0"
                    :disabled="Boolean(disabledReason(plan)) || busy !== null"
                    :title="disabledReason(plan) || ''"
                    @click="pay(plan)"
                >
                    <template v-if="busy === plan.key">Redirecting…</template>
                    <template v-else-if="!configured"
                        >Payments not configured</template
                    >
                    <template v-else>{{
                        plan.current ? 'Renew with Monipay' : 'Pay with Monipay'
                    }}</template>
                </button>
            </li>
            <li
                v-if="!plans.length"
                class="px-5 py-6 text-center text-sm text-zinc-500"
            >
                No paid plans are available.
            </li>
        </ul>

        <div class="border-t border-zinc-800 px-5 py-3.5">
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
