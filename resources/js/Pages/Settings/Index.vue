<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import { useTenant } from '@/composables/useTenant';
import { usePlansModal } from '@/composables/usePlansModal';
import { useToast } from '@/composables/useToast';
import { useTheme } from '@/composables/useTheme';
import {
    CircleHelp,
    Copy,
    Download,
    ExternalLink,
    Eye,
} from '@lucide/vue';
import RowActions from '@/Components/RowActions.vue';
import MonipayUpgrade from '@/Components/Billing/MonipayUpgrade.vue';

const props = defineProps({
    tab: {
        type: String,
        default: 'usage',
    },
    usage: {
        type: Object,
        default: null,
    },
    billing: {
        type: Object,
        default: null,
    },
    payments: {
        type: Object,
        default: null,
    },
    settings: {
        type: Object,
        default: () => ({}),
    },
});

const toast = useToast();
const { theme } = useTheme();
const { open: openPlans } = usePlansModal();
const {
    activeWorkspace,
    activeProviderHealth,
    activeSmtp,
} = useTenant();

const mockUsageFallback = {
    transactional: {
        plan: 'Pro',
        monthly: { used: 0, limit: 50000, renews: '—' },
        daily: 'Unlimited',
    },
    marketing: {
        plan: 'Free',
        contacts: { used: 0, limit: 1000 },
        segments: { used: 0, limit: 3 },
        broadcasts: 'Unlimited',
    },
    team: {
        plan: 'Pro',
        seats: { used: 1, limit: 10 },
    },
};

const usageData = computed(() => props.usage || mockUsageFallback);

const tabs = [
    'usage',
    'billing',
    'smtp',
    'unsubscribe',
    'documents',
];

const tabLabel = (t) => {
    if (t === 'unsubscribe') return 'Unsubscribe page';
    if (t === 'smtp') return 'SMTP';
    return t.charAt(0).toUpperCase() + t.slice(1);
};

const pageTitle = computed(() => {
    const label = tabLabel(props.tab);
    return `Settings · ${label}`;
});

const smtp = computed(
    () =>
        activeSmtp.value || {
            mode: 'api-relay',
            host: 'smtp.maildesk.test',
            port: 465,
            ports: [465, 587, 2587],
            username: 'maildesk',
            password: 'YOUR_API_KEY',
            label: 'Unavailable',
            driver: 'none',
            apiBase: null,
        },
);

const copyValue = async (value, label = 'Value') => {
    try {
        await navigator.clipboard.writeText(value);
        toast.success(`${label} copied.`);
    } catch {
        toast.error('Could not copy.');
    }
};

const settings = ref({
    workspace: activeWorkspace.value?.name || 'Workspace',
    timezone: 'Africa/Lagos',
    replyTo: activeWorkspace.value?.email || 'support@acme.com',
});

const saveWorkspace = () => {
    toast.success('Workspace settings saved locally.');
};

const billing = ref({
    email: props.billing?.email || 'ops@acme.com',
    fullName: props.billing?.fullName || 'Ade Tola',
    country: 'Nigeria',
    address1: '102 Ibadan garage, Ijebu-ode',
    address2: '',
    city: 'Ijebu-Ode',
    postal: '012345',
    state: 'Ogun State',
});

const subscriptions = computed(
    () =>
        props.billing?.subscriptions || [
            {
                id: 'tx',
                name: 'Transactional',
                renews: 'Renews Oct 4',
                quota: '50,000 emails',
                price: '$20.00 / mo',
            },
            {
                id: 'mkt',
                name: 'Marketing',
                renews: null,
                quota: '1,000 contacts',
                price: '$0 / mo',
            },
        ],
);

const paymentMethods = ref([
    {
        id: 1,
        brand: 'Visa',
        last4: '0702',
        expires: '8/2028',
        default: true,
    },
]);

const invoices = [
    { id: 'inv_1', date: 'Sep 4, 2026', amount: '$20.00', status: 'Paid' },
    { id: 'inv_2', date: 'Aug 4, 2026', amount: '$20.00', status: 'Paid' },
];

const countries = [
    'Nigeria',
    'United States',
    'United Kingdom',
    'Germany',
    'Canada',
];
const nigeriaStates = ['Ogun State', 'Lagos', 'Abuja', 'Rivers', 'Kano'];

const subscriptionActions = [
    { id: 'change', label: 'Change plan' },
    { id: 'upgrade', label: 'Upgrade / renew with Monipay' },
    { id: 'cancel', label: 'Cancel subscription', danger: true },
];

const cardActions = [
    { id: 'default', label: 'Make default' },
    { id: 'remove', label: 'Remove card', danger: true },
];

const unsubscribe = ref({
    brand: props.settings?.unsubscribe?.brand || activeWorkspace.value?.name || 'Acme',
    headline:
        props.settings?.unsubscribe?.headline || "You've been unsubscribed",
    message:
        props.settings?.unsubscribe?.message ||
        "Thanks for letting us know. You won't receive marketing emails from us anymore.",
    askReason: props.settings?.unsubscribe?.askReason ?? true,
    showPreferences: props.settings?.unsubscribe?.showPreferences ?? true,
    preferencesUrl: props.settings?.unsubscribe?.preferencesUrl || '',
    buttonLabel: props.settings?.unsubscribe?.buttonLabel || 'Manage preferences',
    accent: props.settings?.unsubscribe?.accent || '#0891b2',
    footer:
        props.settings?.unsubscribe?.footer ||
        'If this was a mistake, you can update your preferences anytime.',
});

const reasons = [
    'Too many emails',
    'Content isn’t relevant',
    'I never signed up',
    'Other',
];

const ringStyle = (used, limit) => {
    const pct = Math.min(100, Math.round((used / limit) * 100));
    const light = theme.value === 'light';
    const fill = light ? '#0891b2' : 'rgb(34 211 238)';
    const track = light ? '#e4e4e7' : '#27272a';
    return {
        background: `conic-gradient(${fill} ${pct}%, ${track} 0)`,
    };
};

const saveUnsubscribe = () => {
    router.put(
        route('settings.update'),
        { unsubscribe: unsubscribe.value },
        {
            preserveScroll: true,
            onSuccess: () => toast.success('Unsubscribe page saved.'),
            onError: () => toast.error('Could not save settings.'),
        },
    );
};

const saveBillingEmail = () => toast.success('Billing email saved.');
const saveAddress = () => toast.success('Billing address saved.');

const onSubscriptionAction = (item) => {
    if (item.id === 'change') openPlans('transactional');
    else if (item.id === 'upgrade')
        document
            .getElementById('monipay-upgrade')
            ?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    else
        toast.info(
            'Plans are prepaid per period and do not auto-renew; contact support to cancel early.',
        );
};

const onCardAction = (card, item) => {
    if (item.id === 'default') {
        paymentMethods.value.forEach((c) => {
            c.default = c.id === card.id;
        });
        toast.success('Default card updated.');
    } else {
        paymentMethods.value = paymentMethods.value.filter(
            (c) => c.id !== card.id,
        );
        toast.info('Card removed.');
    }
};

const addCard = () => toast.info('Card form coming soon (mock).');

const downloadInvoice = (inv) => {
    toast.success(`Downloading invoice ${inv.date} (mock).`);
};
</script>

<template>
    <Head :title="pageTitle" />

    <AppLayout>
        <PageHeader title="Settings" />

        <div
            class="md-tabs mb-8 flex flex-wrap gap-1 rounded-full border border-zinc-800 bg-zinc-950 p-1"
        >
            <Link
                v-for="t in tabs"
                :key="t"
                :href="route('settings', t)"
                preserve-scroll
                class="rounded-full px-3 py-1.5 text-xs transition sm:text-sm"
                :class="
                    tab === t
                        ? 'bg-zinc-800 font-medium text-white shadow-sm'
                        : 'text-zinc-500 hover:text-zinc-300'
                "
            >
                {{ tabLabel(t) }}
            </Link>
        </div>

        <!-- Usage -->
        <div v-if="tab === 'usage'" class="space-y-0 divide-y divide-zinc-800">
            <section class="grid gap-6 py-8 lg:grid-cols-[240px_1fr]">
                <div>
                    <h3 class="text-base font-medium text-white">Transactional</h3>
                    <p class="mt-1 text-sm text-zinc-500">
                        API and dashboard sends for product email.
                    </p>
                    <button type="button" class="md-btn-solid mt-4">Manage</button>
                </div>
                <div class="space-y-4">
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-zinc-400">Plan</span>
                        <span class="rounded-full bg-cyan-400/15 px-2 py-0.5 text-xs text-cyan-300">
                            {{ usageData.transactional.plan }}
                        </span>
                    </div>
                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <div class="text-sm text-white">Monthly limit</div>
                            <div class="text-xs text-zinc-500">
                                {{ usageData.transactional.monthly.used.toLocaleString() }}
                                /
                                {{ usageData.transactional.monthly.limit.toLocaleString() }}
                                · Renews {{ usageData.transactional.monthly.renews }}
                            </div>
                        </div>
                        <span
                            class="h-8 w-8 rounded-full p-[3px]"
                            :style="
                                ringStyle(
                                    usageData.transactional.monthly.used,
                                    usageData.transactional.monthly.limit,
                                )
                            "
                        >
                            <span class="md-ring-hole block h-full w-full rounded-full bg-black" />
                        </span>
                    </div>
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-sm text-white">Daily limit</div>
                            <div class="text-xs text-zinc-500">
                                {{ usageData.transactional.daily }}
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="grid gap-6 py-8 lg:grid-cols-[240px_1fr]">
                <div>
                    <h3 class="text-base font-medium text-white">Marketing</h3>
                    <p class="mt-1 text-sm text-zinc-500">
                        Broadcasts, audience, and segments.
                    </p>
                    <button
                        type="button"
                        class="md-btn-solid mt-4 inline-flex"
                        @click="openPlans('marketing')"
                    >
                        Upgrade
                    </button>
                </div>
                <div class="space-y-4">
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-zinc-400">Plan</span>
                        <span class="rounded-full bg-zinc-800 px-2 py-0.5 text-xs text-zinc-300">
                            {{ usageData.marketing.plan }}
                        </span>
                    </div>
                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <div class="text-sm text-white">Contacts limit</div>
                            <div class="text-xs text-zinc-500">
                                {{ usageData.marketing.contacts.used }} /
                                {{ usageData.marketing.contacts.limit.toLocaleString() }}
                            </div>
                        </div>
                        <span
                            class="h-8 w-8 rounded-full p-[3px]"
                            :style="
                                ringStyle(
                                    usageData.marketing.contacts.used,
                                    usageData.marketing.contacts.limit,
                                )
                            "
                        >
                            <span class="md-ring-hole block h-full w-full rounded-full bg-black" />
                        </span>
                    </div>
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-sm text-white">Segments limit</div>
                            <div class="text-xs text-zinc-500">
                                {{ usageData.marketing.segments.used }} /
                                {{ usageData.marketing.segments.limit }}
                            </div>
                        </div>
                    </div>
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-sm text-white">Broadcasts limit</div>
                            <div class="text-xs text-zinc-500">
                                {{ usageData.marketing.broadcasts }}
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="grid gap-6 py-8 lg:grid-cols-[240px_1fr]">
                <div>
                    <h3 class="text-base font-medium text-white">Users</h3>
                    <p class="mt-1 text-sm text-zinc-500">
                        Mailbox seats for staff, developers, and owners.
                    </p>
                    <Link
                        :href="route('users')"
                        class="md-btn-solid mt-4 inline-flex"
                    >
                        Manage users
                    </Link>
                </div>
                <div class="flex items-center justify-between">
                    <div>
                        <div class="text-sm text-white">Seats</div>
                        <div class="text-xs text-zinc-500">
                            {{ usageData.team.seats.used }} /
                            {{ usageData.team.seats.limit }}
                        </div>
                    </div>
                    <span
                        class="rounded-full bg-cyan-400/15 px-2 py-0.5 text-xs text-cyan-300"
                    >
                        {{ usageData.team.plan }}
                    </span>
                </div>
            </section>
        </div>

        <!-- Billing -->
        <div
            v-else-if="tab === 'billing'"
            class="mx-auto grid max-w-5xl gap-4 lg:grid-cols-2"
        >
            <!-- Subscriptions — full width -->
            <section class="md-card overflow-hidden lg:col-span-2">
                <div
                    class="flex items-center justify-between gap-3 border-b border-zinc-800 px-5 py-3.5"
                >
                    <h3 class="text-sm font-medium text-white">Subscriptions</h3>
                    <button
                        type="button"
                        class="md-btn-ghost"
                        @click="openPlans('transactional')"
                    >
                        View plans
                    </button>
                </div>
                <ul class="divide-y divide-zinc-900">
                    <li
                        v-for="sub in subscriptions"
                        :key="sub.id"
                        class="flex items-center gap-3 px-5 py-3"
                    >
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-sm text-white">{{
                                    sub.name
                                }}</span>
                                <span
                                    v-if="sub.renews"
                                    class="rounded-full bg-zinc-800 px-2 py-0.5 text-[11px] text-zinc-400"
                                >
                                    {{ sub.renews }}
                                </span>
                            </div>
                        </div>
                        <span class="hidden text-sm text-zinc-400 sm:inline">{{
                            sub.quota
                        }}</span>
                        <span
                            class="shrink-0 text-sm tabular-nums text-zinc-300"
                            >{{ sub.price }}</span
                        >
                        <RowActions
                            :items="subscriptionActions"
                            @select="onSubscriptionAction"
                        />
                    </li>
                    <li
                        class="flex items-center justify-between px-5 py-3 text-sm"
                    >
                        <span class="text-zinc-400">Total</span>
                        <span class="font-medium tabular-nums text-white"
                            >$20.00 / mo</span
                        >
                    </li>
                </ul>
            </section>

            <!-- Plan upgrades (Monipay) + payment history — full width -->
            <MonipayUpgrade :payments="payments" class="lg:col-span-2" />

            <!-- Left: email + payment -->
            <div class="space-y-4">
                <section class="md-card space-y-3 p-5">
                    <div>
                        <h3 class="text-sm font-medium text-white">
                            Billing email
                        </h3>
                        <p class="mt-1 text-xs text-zinc-500">
                            Invoices are sent to this address.
                        </p>
                    </div>
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                        <input
                            v-model="billing.email"
                            type="email"
                            class="md-input"
                            placeholder="billing@company.com"
                        />
                        <button
                            type="button"
                            class="md-btn-solid shrink-0"
                            @click="saveBillingEmail"
                        >
                            Save
                        </button>
                    </div>
                </section>

                <section class="md-card overflow-hidden">
                    <div class="border-b border-zinc-800 px-5 py-3.5">
                        <h3 class="text-sm font-medium text-white">
                            Payment methods
                        </h3>
                    </div>
                    <ul class="divide-y divide-zinc-900">
                        <li
                            v-for="card in paymentMethods"
                            :key="card.id"
                            class="flex items-center gap-3 px-5 py-3"
                        >
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="text-sm text-white"
                                        >{{ card.brand }} ···{{
                                            card.last4
                                        }}</span
                                    >
                                    <span
                                        v-if="card.default"
                                        class="rounded-full bg-emerald-500/15 px-2 py-0.5 text-[11px] font-medium text-emerald-400"
                                    >
                                        Default
                                    </span>
                                </div>
                                <p class="mt-0.5 text-xs text-zinc-500">
                                    Expires {{ card.expires }}
                                </p>
                            </div>
                            <RowActions
                                :items="cardActions"
                                @select="onCardAction(card, $event)"
                            />
                        </li>
                        <li
                            v-if="!paymentMethods.length"
                            class="px-5 py-6 text-center text-sm text-zinc-500"
                        >
                            No payment methods yet.
                        </li>
                    </ul>
                    <div class="border-t border-zinc-800 px-5 py-3">
                        <button
                            type="button"
                            class="md-btn-ghost"
                            @click="addCard"
                        >
                            Add new card
                        </button>
                    </div>
                </section>

                <section class="md-card overflow-hidden">
                    <div class="border-b border-zinc-800 px-5 py-3.5">
                        <h3 class="text-sm font-medium text-white">Invoices</h3>
                    </div>
                    <ul class="divide-y divide-zinc-900">
                        <li
                            v-for="inv in invoices"
                            :key="inv.id"
                            class="flex items-center gap-3 px-5 py-3"
                        >
                            <span class="text-sm text-zinc-300">{{
                                inv.date
                            }}</span>
                            <span
                                class="text-sm tabular-nums text-zinc-400"
                                >{{ inv.amount }}</span
                            >
                            <span
                                class="rounded-full bg-emerald-500/15 px-2 py-0.5 text-[11px] font-medium text-emerald-400"
                            >
                                {{ inv.status }}
                            </span>
                            <button
                                type="button"
                                class="ml-auto inline-flex h-8 w-8 items-center justify-center rounded-full border border-zinc-800 text-zinc-400 transition hover:border-zinc-700 hover:text-zinc-200"
                                title="Download"
                                @click="downloadInvoice(inv)"
                            >
                                <Download :size="14" />
                            </button>
                        </li>
                    </ul>
                </section>
            </div>

            <!-- Right: address -->
            <section class="md-card space-y-4 p-5">
                <h3 class="text-sm font-medium text-white">Billing address</h3>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label class="mb-1.5 block text-xs text-zinc-500"
                            >Full name</label
                        >
                        <input v-model="billing.fullName" class="md-input" />
                    </div>
                    <div>
                        <label class="mb-1.5 block text-xs text-zinc-500"
                            >Country</label
                        >
                        <select v-model="billing.country" class="md-input">
                            <option
                                v-for="c in countries"
                                :key="c"
                                :value="c"
                            >
                                {{ c }}
                            </option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-xs text-zinc-500"
                            >State</label
                        >
                        <select v-model="billing.state" class="md-input">
                            <option
                                v-for="s in nigeriaStates"
                                :key="s"
                                :value="s"
                            >
                                {{ s }}
                            </option>
                        </select>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="mb-1.5 block text-xs text-zinc-500"
                            >Address line 1</label
                        >
                        <input v-model="billing.address1" class="md-input" />
                    </div>
                    <div class="sm:col-span-2">
                        <label class="mb-1.5 block text-xs text-zinc-500"
                            >Address line 2</label
                        >
                        <input
                            v-model="billing.address2"
                            class="md-input"
                            placeholder="Apt., suite, unit (optional)"
                        />
                    </div>
                    <div>
                        <label class="mb-1.5 block text-xs text-zinc-500"
                            >City</label
                        >
                        <input v-model="billing.city" class="md-input" />
                    </div>
                    <div>
                        <label class="mb-1.5 block text-xs text-zinc-500"
                            >Postal code</label
                        >
                        <input v-model="billing.postal" class="md-input" />
                    </div>
                </div>

                <button
                    type="button"
                    class="md-btn-solid"
                    @click="saveAddress"
                >
                    Save
                </button>
            </section>
        </div>

        <!-- SMTP -->
        <div v-else-if="tab === 'smtp'" class="mx-auto max-w-xl">
            <section class="md-card space-y-5 p-5 sm:p-6">
                <div>
                    <h3 class="text-sm font-medium text-white">SMTP</h3>
                    <p class="mt-1 text-sm text-zinc-500">
                        Credentials for
                        <span class="text-zinc-300">{{ smtp.label }}</span>
                        ({{ smtp.driver }})
                        <span v-if="smtp.mode === 'smtp'">
                            · direct SMTP relay</span
                        >
                        <span v-else> · MailDesk API relay</span>.
                        See
                        <Link
                            :href="route('docs')"
                            class="inline-flex items-center gap-0.5 text-cyan-300 hover:underline"
                        >
                            documentation
                            <ExternalLink :size="12" />
                        </Link>
                        for more information.
                    </p>
                    <div
                        v-if="!activeProviderHealth.ok"
                        class="mt-3 rounded-lg border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-xs text-rose-200"
                    >
                        This workspace has no active mail provider. Ask a
                        platform admin to assign one.
                    </div>
                </div>

                <div class="space-y-4">
                    <div>
                        <label class="mb-1.5 block text-xs text-zinc-500"
                            >Host</label
                        >
                        <div class="relative">
                            <input
                                class="md-input pr-10 font-mono text-sm"
                                readonly
                                :value="smtp.host"
                            />
                            <button
                                type="button"
                                class="absolute right-2 top-1/2 -translate-y-1/2 rounded-md p-1.5 text-zinc-500 transition hover:bg-zinc-900 hover:text-zinc-200"
                                title="Copy"
                                @click="copyValue(smtp.host, 'Host')"
                            >
                                <Copy :size="14" />
                            </button>
                        </div>
                    </div>

                    <div>
                        <label class="mb-1.5 block text-xs text-zinc-500"
                            >Port</label
                        >
                        <div class="relative">
                            <input
                                class="md-input pr-10 font-mono text-sm"
                                readonly
                                :value="smtp.port"
                            />
                            <button
                                type="button"
                                class="absolute right-2 top-1/2 -translate-y-1/2 rounded-md p-1.5 text-zinc-500 transition hover:bg-zinc-900 hover:text-zinc-200"
                                title="Copy"
                                @click="copyValue(String(smtp.port), 'Port')"
                            >
                                <Copy :size="14" />
                            </button>
                        </div>
                        <p
                            class="mt-2 flex flex-wrap items-center gap-x-1.5 gap-y-1 text-xs text-zinc-500"
                        >
                            <span>For encrypted/TLS connections use</span>
                            <template
                                v-for="(p, i) in smtp.ports"
                                :key="p"
                            >
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1 rounded-md border border-zinc-800 bg-zinc-950 px-1.5 py-0.5 font-mono text-[11px] text-zinc-300 transition hover:border-zinc-700 hover:text-white"
                                    @click="copyValue(String(p), `Port ${p}`)"
                                >
                                    {{ p }}
                                    <Copy :size="10" />
                                </button>
                                <span v-if="i < smtp.ports.length - 1">{{
                                    i === smtp.ports.length - 2 ? 'or' : ','
                                }}</span>
                            </template>
                            <span>.</span>
                        </p>
                    </div>

                    <div>
                        <label class="mb-1.5 block text-xs text-zinc-500"
                            >Username</label
                        >
                        <div class="relative">
                            <input
                                class="md-input pr-10 font-mono text-sm"
                                readonly
                                :value="smtp.username"
                            />
                            <button
                                type="button"
                                class="absolute right-2 top-1/2 -translate-y-1/2 rounded-md p-1.5 text-zinc-500 transition hover:bg-zinc-900 hover:text-zinc-200"
                                title="Copy"
                                @click="copyValue(smtp.username, 'Username')"
                            >
                                <Copy :size="14" />
                            </button>
                        </div>
                    </div>

                    <div>
                        <label
                            class="mb-1.5 flex items-center gap-1.5 text-xs text-zinc-500"
                        >
                            Password
                            <span
                                class="inline-flex text-zinc-600"
                                title="Use any API key with sending access as the SMTP password."
                            >
                                <CircleHelp :size="12" />
                            </span>
                        </label>
                        <div class="relative">
                            <input
                                class="md-input pr-10 font-mono text-sm"
                                readonly
                                :value="smtp.password"
                            />
                            <button
                                type="button"
                                class="absolute right-2 top-1/2 -translate-y-1/2 rounded-md p-1.5 text-zinc-500 transition hover:bg-zinc-900 hover:text-zinc-200"
                                title="Copy"
                                @click="
                                    copyValue(smtp.password, 'Password hint')
                                "
                            >
                                <Copy :size="14" />
                            </button>
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <div
            v-else-if="tab === 'unsubscribe'"
            class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_380px]"
        >
            <div class="md-card space-y-6 p-6">
                <div>
                    <h3 class="font-medium text-white">Unsubscribe page</h3>
                    <p class="mt-1 text-sm text-zinc-400">
                        Customize the confirmation contacts see when they opt
                        out of marketing mail.
                    </p>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="mb-1.5 block text-xs text-zinc-500"
                            >Brand name</label
                        >
                        <input
                            v-model="unsubscribe.brand"
                            class="md-input"
                            placeholder="Acme"
                        />
                    </div>
                    <div>
                        <label class="mb-1.5 block text-xs text-zinc-500"
                            >Accent color</label
                        >
                        <div class="flex items-center gap-2">
                            <input
                                v-model="unsubscribe.accent"
                                type="color"
                                class="h-10 w-12 cursor-pointer rounded-lg border border-zinc-800 bg-transparent p-1"
                            />
                            <input
                                v-model="unsubscribe.accent"
                                class="md-input font-mono text-xs"
                            />
                        </div>
                    </div>
                </div>

                <div>
                    <label class="mb-1.5 block text-xs text-zinc-500"
                        >Headline</label
                    >
                    <input
                        v-model="unsubscribe.headline"
                        class="md-input"
                        placeholder="You've been unsubscribed"
                    />
                </div>

                <div>
                    <label class="mb-1.5 block text-xs text-zinc-500"
                        >Confirmation message</label
                    >
                    <textarea
                        v-model="unsubscribe.message"
                        rows="3"
                        class="md-input resize-y"
                    />
                </div>

                <div>
                    <label class="mb-1.5 block text-xs text-zinc-500"
                        >Footer note</label
                    >
                    <input
                        v-model="unsubscribe.footer"
                        class="md-input"
                        placeholder="Optional supporting line"
                    />
                </div>

                <div class="space-y-3 rounded-xl border border-zinc-800 p-4">
                    <label
                        class="flex items-center justify-between gap-3 text-sm text-zinc-200"
                    >
                        <span>
                            Ask for a reason
                            <span class="mt-0.5 block text-xs text-zinc-500"
                                >Optional feedback before they leave</span
                            >
                        </span>
                        <input
                            v-model="unsubscribe.askReason"
                            type="checkbox"
                            class="rounded border-zinc-700 bg-zinc-900 text-cyan-400 focus:ring-cyan-400/40"
                        />
                    </label>
                    <label
                        class="flex items-center justify-between gap-3 text-sm text-zinc-200"
                    >
                        <span>
                            Show preferences link
                            <span class="mt-0.5 block text-xs text-zinc-500"
                                >Let contacts manage topics instead of leaving</span
                            >
                        </span>
                        <input
                            v-model="unsubscribe.showPreferences"
                            type="checkbox"
                            class="rounded border-zinc-700 bg-zinc-900 text-cyan-400 focus:ring-cyan-400/40"
                        />
                    </label>
                </div>

                <div
                    v-if="unsubscribe.showPreferences"
                    class="grid gap-4 sm:grid-cols-2"
                >
                    <div>
                        <label class="mb-1.5 block text-xs text-zinc-500"
                            >Button label</label
                        >
                        <input
                            v-model="unsubscribe.buttonLabel"
                            class="md-input"
                        />
                    </div>
                    <div>
                        <label class="mb-1.5 block text-xs text-zinc-500"
                            >Preferences URL</label
                        >
                        <input
                            v-model="unsubscribe.preferencesUrl"
                            class="md-input"
                            placeholder="https://…"
                        />
                    </div>
                </div>

                <div class="flex flex-wrap gap-2 pt-1">
                    <button
                        type="button"
                        class="md-btn-primary"
                        @click="saveUnsubscribe"
                    >
                        Save page
                    </button>
                    <a
                        :href="unsubscribe.preferencesUrl"
                        target="_blank"
                        rel="noreferrer"
                        class="md-btn-ghost"
                    >
                        <ExternalLink :size="14" />
                        Open preferences
                    </a>
                </div>
            </div>

            <!-- Live preview -->
            <div class="xl:sticky xl:top-20 xl:self-start">
                <div
                    class="mb-3 flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-zinc-500"
                >
                    <Eye :size="12" />
                    Live preview
                </div>
                <div
                    class="overflow-hidden rounded-2xl border border-zinc-800 bg-zinc-100 shadow-2xl shadow-black/30"
                >
                    <div
                        class="flex items-center gap-1.5 border-b border-zinc-200 bg-white px-3 py-2"
                    >
                        <span class="h-2 w-2 rounded-full bg-rose-400" />
                        <span class="h-2 w-2 rounded-full bg-amber-400" />
                        <span class="h-2 w-2 rounded-full bg-emerald-400" />
                        <span class="ml-2 truncate text-[11px] text-zinc-400"
                            >unsubscribe.acme.com</span
                        >
                    </div>
                    <div
                        class="flex min-h-[420px] flex-col items-center justify-center bg-zinc-50 px-6 py-10 text-center"
                    >
                        <div
                            class="mb-5 flex h-11 w-11 items-center justify-center rounded-xl text-sm font-semibold text-white"
                            :style="{ backgroundColor: unsubscribe.accent }"
                        >
                            {{ unsubscribe.brand.slice(0, 1).toUpperCase() }}
                        </div>
                        <p
                            class="text-xs font-medium uppercase tracking-[0.14em] text-zinc-400"
                        >
                            {{ unsubscribe.brand }}
                        </p>
                        <h4
                            class="mt-3 max-w-xs text-xl font-semibold tracking-tight text-zinc-900"
                        >
                            {{ unsubscribe.headline }}
                        </h4>
                        <p class="mt-2 max-w-sm text-sm leading-relaxed text-zinc-500">
                            {{ unsubscribe.message }}
                        </p>

                        <div
                            v-if="unsubscribe.askReason"
                            class="mt-6 w-full max-w-sm rounded-xl border border-zinc-200 bg-white p-4 text-left"
                        >
                            <p class="text-xs font-medium text-zinc-700">
                                Why are you leaving? (optional)
                            </p>
                            <div class="mt-3 space-y-2">
                                <label
                                    v-for="reason in reasons"
                                    :key="reason"
                                    class="flex items-center gap-2 text-sm text-zinc-600"
                                >
                                    <input
                                        type="radio"
                                        name="unsub-reason"
                                        class="border-zinc-300 text-cyan-600 focus:ring-cyan-500/30"
                                    />
                                    {{ reason }}
                                </label>
                            </div>
                        </div>

                        <a
                            v-if="unsubscribe.showPreferences"
                            :href="unsubscribe.preferencesUrl"
                            class="mt-6 inline-flex rounded-full px-4 py-2 text-sm font-medium text-white"
                            :style="{ backgroundColor: unsubscribe.accent }"
                            @click.prevent
                        >
                            {{ unsubscribe.buttonLabel }}
                        </a>

                        <p
                            v-if="unsubscribe.footer"
                            class="mt-6 max-w-xs text-[11px] leading-relaxed text-zinc-400"
                        >
                            {{ unsubscribe.footer }}
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <div v-else class="md-card max-w-2xl space-y-3 p-6">
            <h3 class="font-medium text-white">Documents</h3>
            <a href="#" class="flex items-center gap-2 text-sm text-cyan-300 hover:underline">
                DPA <ExternalLink :size="14" />
            </a>
            <a href="#" class="flex items-center gap-2 text-sm text-cyan-300 hover:underline">
                Privacy policy <ExternalLink :size="14" />
            </a>
            <Link
                :href="route('profile.edit')"
                class="mt-4 inline-flex text-sm text-zinc-400 hover:text-white"
            >
                Account profile →
            </Link>
        </div>

        <section
            v-if="tab === 'usage'"
            class="mt-8 md-card space-y-4 p-5"
        >
            <h3 class="text-sm font-medium text-white">Workspace defaults</h3>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="mb-1.5 block text-xs text-zinc-500"
                        >Workspace name</label
                    >
                    <input v-model="settings.workspace" class="md-input" />
                </div>
                <div>
                    <label class="mb-1.5 block text-xs text-zinc-500"
                        >Default reply-to</label
                    >
                    <input v-model="settings.replyTo" class="md-input" />
                </div>
            </div>
            <div>
                <label class="mb-1.5 block text-xs text-zinc-500"
                    >Assigned mail provider</label
                >
                <div
                    class="rounded-xl border px-4 py-3"
                    :class="
                        activeProviderHealth.ok
                            ? 'border-zinc-800'
                            : 'border-rose-500/40 bg-rose-500/5'
                    "
                >
                    <div class="text-sm font-medium text-white">
                        {{
                            activeProviderHealth.provider?.name ||
                            activeWorkspace?.providerName ||
                            'Unassigned'
                        }}
                    </div>
                    <div class="mt-1 text-xs capitalize text-zinc-500">
                        <span v-if="activeProviderHealth.ok">
                            {{ activeProviderHealth.provider?.driver }} ·
                            managed by SaaS Admin
                        </span>
                        <span v-else>
                            {{ activeProviderHealth.reason }} · cannot send until
                            fixed
                        </span>
                    </div>
                </div>
            </div>
            <button type="button" class="md-btn-primary" @click="saveWorkspace">
                Save changes
            </button>
        </section>
    </AppLayout>
</template>
