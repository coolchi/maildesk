<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import { useTenant } from '@/composables/useTenant';
import { usePlansModal } from '@/composables/usePlansModal';
import { useToast } from '@/composables/useToast';
import { useTheme } from '@/composables/useTheme';
import {
    Download,
    ExternalLink,
    Eye,
    Users,
} from '@lucide/vue';
import RowActions from '@/Components/RowActions.vue';
import WysiwygEditor from '@/Components/WysiwygEditor.vue';
import EmailFrame from '@/Components/EmailFrame.vue';
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
    mailboxes: {
        type: Array,
        default: () => [],
    },
    smtp: {
        type: Object,
        default: null,
    },
    team: {
        type: Array,
        default: () => [],
    },
    canImpersonateTeam: {
        type: Boolean,
        default: false,
    },
    joinUrl: {
        type: String,
        default: '',
    },
    invitations: {
        type: Array,
        default: () => [],
    },
});

const page = usePage();
const toast = useToast();
const { theme } = useTheme();
const { open: openPlans } = usePlansModal();
const {
    activeWorkspace,
    activeProviderHealth,
    activeSmtp,
} = useTenant();

const inviteEmail = ref('');
const inviteRole = ref('member');
const inviting = ref(false);

const sendInvite = () => {
    if (!inviteEmail.value.trim()) {
        toast.info('Enter an email address to invite.');
        return;
    }
    inviting.value = true;
    router.post(
        route('invitations.store'),
        { email: inviteEmail.value.trim(), role: inviteRole.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                inviteEmail.value = '';
                toast.success('Invitation sent.');
            },
            onFinish: () => {
                inviting.value = false;
            },
        },
    );
};

const cancelInvite = (invitation) => {
    router.delete(route('invitations.destroy', invitation.id), {
        preserveScroll: true,
        onSuccess: () => toast.success('Invitation cancelled.'),
    });
};

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
    'team',
    'users',
    'smtp',
    'signature',
    'unsubscribe',
    'documents',
];

const tabLabel = (t) => {
    if (t === 'unsubscribe') return 'Unsubscribe page';
    if (t === 'smtp') return 'SMTP';
    if (t === 'signature') return 'Signature';
    if (t === 'users') return 'Users';
    return t.charAt(0).toUpperCase() + t.slice(1);
};

const pageTitle = computed(() => {
    const label = tabLabel(props.tab);
    return `Settings · ${label}`;
});

onMounted(() => {
    const error = page.props.flash?.error;
    if (error && /impersonat|log in as/i.test(error)) toast.error(error);
});

const registration = ref({
    enabled: Boolean(props.settings?.user_registration?.enabled),
    approval: props.settings?.user_registration?.approval || 'auto',
    default_role: props.settings?.user_registration?.default_role || 'staff',
    default_inbox: props.settings?.user_registration?.default_inbox ?? true,
    default_transactional:
        props.settings?.user_registration?.default_transactional ?? false,
    default_marketing:
        props.settings?.user_registration?.default_marketing ?? false,
});
const registrationSaving = ref(false);

const saveRegistration = () => {
    registrationSaving.value = true;
    router.put(
        route('settings.update'),
        { user_registration: registration.value },
        {
            preserveScroll: true,
            onSuccess: () => toast.success('User registration settings saved.'),
            onError: () => toast.error('Could not save settings.'),
            onFinish: () => {
                registrationSaving.value = false;
            },
        },
    );
};

const copyJoinUrl = async () => {
    if (!props.joinUrl) return;
    try {
        await navigator.clipboard.writeText(props.joinUrl);
        toast.success('Join link copied.');
    } catch {
        toast.error('Could not copy link.');
    }
};

// Workspace SMTP server. The saved password is never sent to the browser;
// the server only tells us whether one exists (has_password).
const smtpSettings = computed(() => props.smtp || {});
const smtpCanManage = computed(() => Boolean(smtpSettings.value.can_manage));
const platformSmtp = computed(() => activeSmtp.value);
const smtpForm = ref({
    enabled: Boolean(props.smtp?.enabled),
    host: props.smtp?.host || '',
    port: props.smtp?.port || 587,
    username: props.smtp?.username || '',
    password: '',
    clear_password: false,
    encryption: props.smtp?.encryption || 'tls',
});
const smtpErrors = ref({});
const smtpSaving = ref(false);
const encryptionOptions = [
    { value: 'tls', label: 'TLS (STARTTLS, usually port 587)' },
    { value: 'ssl', label: 'SSL (implicit TLS, usually port 465)' },
    { value: 'none', label: 'None (unencrypted, not recommended)' },
];

const saveSmtp = () => {
    smtpSaving.value = true;
    smtpErrors.value = {};
    router.put(
        route('settings.smtp.update'),
        {
            ...smtpForm.value,
            port: smtpForm.value.port === '' ? null : Number(smtpForm.value.port),
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                smtpForm.value.password = '';
                smtpForm.value.clear_password = false;
                toast.success('SMTP settings saved.');
            },
            onError: (errors) => {
                smtpErrors.value = errors;
                toast.error('Could not save SMTP settings.');
            },
            onFinish: () => {
                smtpSaving.value = false;
            },
        },
    );
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

const paymentMethods = ref([]);

const invoices = [];

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
    { id: 'history', label: 'Payment history' },
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

const signature = ref({
    enabled: props.settings?.signature?.enabled ?? false,
    html: props.settings?.signature?.html || '',
    api: props.settings?.signature?.api ?? false,
    broadcasts: props.settings?.signature?.broadcasts ?? true,
});
const mailboxSignatures = ref(
    (props.mailboxes || []).map((m) => ({
        id: m.id,
        email: m.email,
        display_name: m.display_name,
        signature: m.signature || '',
        custom: !!m.signature,
    })),
);
const previewMailbox = ref('');
const signatureSaving = ref(false);

const signaturePreviewHtml = computed(() => {
    const override = mailboxSignatures.value.find(
        (m) => String(m.id) === String(previewMailbox.value) && m.custom,
    );
    const sig = override?.signature || signature.value.html;
    const body =
        '<p style="font-family:system-ui,sans-serif;font-size:14px;color:#18181b">Hi Sam,</p>' +
        '<p style="font-family:system-ui,sans-serif;font-size:14px;color:#18181b">Thanks for getting in touch. Your order has shipped and should arrive on Tuesday.</p>';
    if (!signature.value.enabled || !sig || sig === '<p></p>') return body;
    return (
        body +
        '<div style="margin-top:16px;padding-top:12px;border-top:1px solid #e4e4e7;color:#52525b;font-size:13px;font-family:system-ui,sans-serif">' +
        sig +
        '</div>'
    );
});

const saveSignature = () => {
    signatureSaving.value = true;
    router.put(
        route('settings.update'),
        {
            signature: signature.value,
            mailbox_signatures: mailboxSignatures.value.map((m) => ({
                id: m.id,
                signature: m.custom ? m.signature : '',
            })),
        },
        {
            preserveScroll: true,
            onSuccess: () => toast.success('Signature saved.'),
            onError: () => toast.error('Could not save the signature.'),
            onFinish: () => {
                signatureSaving.value = false;
            },
        },
    );
};

const saveBillingEmail = () => toast.success('Billing email saved.');
const saveAddress = () => toast.success('Billing address saved.');

const onSubscriptionAction = (item) => {
    if (item.id === 'change') openPlans('transactional');
    else if (item.id === 'history')
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

const addCard = () =>
    toast.info('Card payments are not used — upgrade with Monipay from View plans.');
const downloadInvoice = () => {
    toast.info('Use Payment history below for Monipay receipts.');
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
                    <h3 class="text-base font-medium text-white">Mailboxes</h3>
                    <p class="mt-1 text-sm text-zinc-500">
                        Addresses for staff, developers, and owners.
                    </p>
                    <Link
                        :href="route('users')"
                        class="md-btn-solid mt-4 inline-flex"
                    >
                        Manage mailboxes
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

        <!-- Team accounts -->
        <div
            v-else-if="tab === 'team'"
            class="mx-auto max-w-3xl space-y-6"
            data-testid="team-members"
        >
            <section class="md-card overflow-hidden">
                <div
                    class="flex flex-wrap items-start justify-between gap-3 border-b border-zinc-800 px-5 py-4"
                >
                    <div class="flex items-start gap-3">
                        <div
                            class="mt-0.5 flex h-9 w-9 items-center justify-center rounded-lg border border-cyan-400/20 bg-cyan-400/10 text-cyan-300"
                        >
                            <Users :size="16" />
                        </div>
                        <div>
                            <h2 class="text-base font-medium text-white">
                                Team accounts
                            </h2>
                            <p class="mt-1 text-sm text-zinc-500">
                                Workspace owners and admins. Mailbox sign-in
                                users are managed under Users — including Log in
                                as.
                            </p>
                        </div>
                    </div>
                    <Link
                        :href="route('users')"
                        class="md-btn-solid shrink-0 text-xs"
                    >
                        Manage users
                    </Link>
                </div>

                <div
                    v-if="!team.length"
                    class="px-5 py-8 text-center text-sm text-zinc-500"
                >
                    No team accounts yet.
                </div>

                <div v-else class="divide-y divide-zinc-800/80">
                    <div
                        v-for="member in team"
                        :key="member.id"
                        class="flex items-center gap-3 px-5 py-3.5"
                    >
                        <div
                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-zinc-800 text-xs font-medium text-zinc-300"
                        >
                            {{ member.name.slice(0, 1).toUpperCase() }}
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="truncate text-sm font-medium text-white">
                                {{ member.name }}
                                <span
                                    class="ml-1.5 text-xs font-normal capitalize text-zinc-500"
                                    >{{ member.role }}</span
                                >
                            </div>
                            <div
                                class="truncate font-mono text-xs text-zinc-400"
                            >
                                {{ member.email }}
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="md-card overflow-hidden">
                <div class="border-b border-zinc-800 px-5 py-4">
                    <h2 class="text-base font-medium text-white">
                        Invite by email
                    </h2>
                    <p class="mt-1 text-sm text-zinc-500">
                        Send a closed-team invite. Open self-serve signup stays
                        at /join when enabled.
                    </p>
                </div>
                <form
                    class="flex flex-wrap items-end gap-3 px-5 py-4"
                    @submit.prevent="sendInvite"
                >
                    <div class="min-w-[14rem] flex-1">
                        <label class="mb-1 block text-xs text-zinc-500"
                            >Email</label
                        >
                        <input
                            v-model="inviteEmail"
                            type="email"
                            required
                            class="md-input w-full"
                            placeholder="teammate@company.com"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-zinc-500"
                            >Role</label
                        >
                        <select v-model="inviteRole" class="md-input">
                            <option value="member">Member</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                    <button
                        type="submit"
                        class="md-btn-solid"
                        :disabled="inviting"
                    >
                        Send invite
                    </button>
                </form>
                <div
                    v-if="invitations.length"
                    class="divide-y divide-zinc-800/80 border-t border-zinc-800"
                >
                    <div
                        v-for="invitation in invitations"
                        :key="invitation.id"
                        class="flex items-center gap-3 px-5 py-3"
                    >
                        <div class="min-w-0 flex-1">
                            <div class="truncate text-sm text-white">
                                {{ invitation.email }}
                                <span
                                    class="ml-1.5 text-xs capitalize text-zinc-500"
                                    >{{ invitation.role }}</span
                                >
                            </div>
                            <div class="text-xs text-zinc-500">
                                Expires {{ invitation.expires_at }} ·
                                {{ invitation.status }}
                            </div>
                        </div>
                        <button
                            type="button"
                            class="text-xs text-zinc-500 hover:text-red-400"
                            @click="cancelInvite(invitation)"
                        >
                            Cancel
                        </button>
                    </div>
                </div>
            </section>

            <p class="text-xs text-zinc-600">
                To create a sign-in mailbox user or Log in as them, open
                <Link
                    :href="route('users')"
                    class="text-cyan-400 hover:text-cyan-300"
                    >Users</Link
                >.
            </p>
        </div>

        <!-- User registration -->
        <div
            v-else-if="tab === 'users'"
            class="mx-auto max-w-3xl space-y-6"
            data-testid="user-registration-settings"
        >
            <section class="md-card overflow-hidden">
                <div
                    class="flex flex-wrap items-start justify-between gap-3 border-b border-zinc-800 px-5 py-4"
                >
                    <div class="flex items-start gap-3">
                        <div
                            class="mt-0.5 flex h-9 w-9 items-center justify-center rounded-lg border border-cyan-400/20 bg-cyan-400/10 text-cyan-300"
                        >
                            <Users :size="16" />
                        </div>
                        <div>
                            <h2 class="text-base font-medium text-white">
                                User registration
                            </h2>
                            <p class="mt-1 text-sm text-zinc-500">
                                Let students and staff create their own mailbox
                                sign-in accounts on this workspace.
                            </p>
                        </div>
                    </div>
                    <Link
                        :href="route('users')"
                        class="md-btn-solid shrink-0 text-xs"
                    >
                        Pending users
                    </Link>
                </div>

                <div class="space-y-5 px-5 py-5">
                    <label class="flex items-start gap-3">
                        <input
                            v-model="registration.enabled"
                            type="checkbox"
                            class="mt-1 rounded border-zinc-700 bg-zinc-900 text-cyan-400 focus:ring-cyan-400/40"
                            data-testid="registration-enabled"
                        />
                        <span>
                            <span class="block text-sm font-medium text-white"
                                >Allow user registration</span
                            >
                            <span class="mt-0.5 block text-xs text-zinc-500"
                                >Shows a public /join page on this
                                workspace.</span
                            >
                        </span>
                    </label>

                    <div v-if="registration.enabled" class="space-y-5 border-t border-zinc-800 pt-5">
                        <div>
                            <label
                                class="mb-1.5 block text-xs text-zinc-500"
                                for="registration-approval"
                                >Approval</label
                            >
                            <select
                                id="registration-approval"
                                v-model="registration.approval"
                                class="md-input"
                                data-testid="registration-approval"
                            >
                                <option value="auto">Auto-approve</option>
                                <option value="manual">Require approval</option>
                            </select>
                            <p class="mt-1.5 text-xs text-zinc-600">
                                {{
                                    registration.approval === 'manual'
                                        ? 'New accounts stay pending until you approve them on Users.'
                                        : 'New accounts can sign in immediately.'
                                }}
                            </p>
                        </div>

                        <div>
                            <label
                                class="mb-1.5 block text-xs text-zinc-500"
                                for="registration-role"
                                >Default role</label
                            >
                            <select
                                id="registration-role"
                                v-model="registration.default_role"
                                class="md-input"
                            >
                                <option value="staff">Staff</option>
                                <option value="developer">Developer</option>
                                <option value="admin">Admin</option>
                            </select>
                        </div>

                        <div>
                            <div class="mb-2 text-xs text-zinc-500">
                                Default product access
                            </div>
                            <div class="flex flex-wrap gap-4">
                                <label class="flex items-center gap-2 text-sm text-zinc-300">
                                    <input
                                        v-model="registration.default_inbox"
                                        type="checkbox"
                                        class="rounded border-zinc-700 bg-zinc-900 text-cyan-400 focus:ring-cyan-400/40"
                                    />
                                    Inbox
                                </label>
                                <label class="flex items-center gap-2 text-sm text-zinc-300">
                                    <input
                                        v-model="
                                            registration.default_transactional
                                        "
                                        type="checkbox"
                                        class="rounded border-zinc-700 bg-zinc-900 text-cyan-400 focus:ring-cyan-400/40"
                                    />
                                    Transactional
                                </label>
                                <label class="flex items-center gap-2 text-sm text-zinc-300">
                                    <input
                                        v-model="registration.default_marketing"
                                        type="checkbox"
                                        class="rounded border-zinc-700 bg-zinc-900 text-cyan-400 focus:ring-cyan-400/40"
                                    />
                                    Marketing
                                </label>
                            </div>
                        </div>

                        <div v-if="joinUrl">
                            <div class="mb-1.5 text-xs text-zinc-500">
                                Join link
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <code
                                    class="min-w-0 flex-1 truncate rounded-md border border-zinc-800 bg-zinc-950 px-3 py-2 font-mono text-xs text-cyan-300"
                                    >{{ joinUrl }}</code
                                >
                                <button
                                    type="button"
                                    class="md-btn-ghost text-xs"
                                    @click="copyJoinUrl"
                                >
                                    Copy
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="border-t border-zinc-800 pt-4">
                        <button
                            type="button"
                            class="md-btn-solid"
                            data-testid="registration-save"
                            :disabled="registrationSaving"
                            @click="saveRegistration"
                        >
                            {{
                                registrationSaving
                                    ? 'Saving…'
                                    : 'Save registration settings'
                            }}
                        </button>
                    </div>
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

            <!-- Payment history (plan pay starts from View plans → Monipay) -->
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
        <div
            v-else-if="tab === 'smtp'"
            class="mx-auto max-w-xl"
            data-testid="smtp-settings"
        >
            <section class="md-card space-y-5 p-5 sm:p-6">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h3 class="text-sm font-medium text-white">SMTP server</h3>
                        <p class="mt-1 text-sm text-zinc-500">
                            Send this workspace's email through your own SMTP
                            server. When it's off, mail goes out through
                            <span class="text-zinc-300">{{
                                activeProviderHealth.provider?.name ||
                                'the platform provider'
                            }}</span>.
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
                    </div>
                    <label class="flex shrink-0 items-center gap-2 text-sm text-zinc-200">
                        <input
                            v-model="smtpForm.enabled"
                            type="checkbox"
                            data-testid="smtp-enabled"
                            :disabled="!smtpCanManage"
                            class="rounded border-zinc-700 bg-zinc-900 text-cyan-400 focus:ring-cyan-400/40"
                        />
                        Use SMTP for sending
                    </label>
                </div>

                <div
                    v-if="!smtpSettings.configured"
                    class="rounded-lg border border-zinc-800 bg-zinc-950 px-3 py-2 text-xs text-zinc-400"
                >
                    No SMTP server is set up yet. Enter your server's details,
                    turn on “Use SMTP for sending” and save.
                </div>
                <div
                    v-else-if="smtpSettings.sending_via === 'smtp' && smtpSettings.enabled"
                    class="rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-3 py-2 text-xs text-emerald-200"
                >
                    This workspace sends through {{ smtpSettings.host }}.
                </div>
                <div
                    v-if="!smtpForm.enabled && !activeProviderHealth.ok"
                    class="rounded-lg border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-xs text-rose-200"
                >
                    This workspace has no active mail provider. Ask a platform
                    admin to assign one.
                </div>
                <p
                    v-if="platformSmtp && !smtpForm.enabled"
                    class="text-xs text-zinc-500"
                >
                    The platform provider {{ platformSmtp.label }} relays over
                    SMTP{{ platformSmtp.host ? ` (${platformSmtp.host})` : '' }}.
                </p>
                <div
                    v-if="!smtpCanManage"
                    class="rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-xs text-amber-200"
                >
                    Only workspace owners and admins can change SMTP settings.
                </div>

                <fieldset class="space-y-4" :disabled="!smtpCanManage">
                    <div class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_120px]">
                        <div>
                            <label class="mb-1.5 block text-xs text-zinc-500" for="smtp-host">Host</label>
                            <input
                                id="smtp-host"
                                v-model="smtpForm.host"
                                class="md-input font-mono text-sm"
                                placeholder="smtp.example.com"
                                autocomplete="off"
                                data-testid="smtp-host"
                            />
                            <p v-if="smtpErrors.host" class="mt-1 text-xs text-rose-300">{{ smtpErrors.host }}</p>
                        </div>
                        <div>
                            <label class="mb-1.5 block text-xs text-zinc-500" for="smtp-port">Port</label>
                            <input
                                id="smtp-port"
                                v-model="smtpForm.port"
                                type="number"
                                min="1"
                                max="65535"
                                class="md-input font-mono text-sm"
                                placeholder="587"
                                data-testid="smtp-port"
                            />
                            <p v-if="smtpErrors.port" class="mt-1 text-xs text-rose-300">{{ smtpErrors.port }}</p>
                        </div>
                    </div>

                    <div>
                        <label class="mb-1.5 block text-xs text-zinc-500" for="smtp-encryption">Encryption</label>
                        <select
                            id="smtp-encryption"
                            v-model="smtpForm.encryption"
                            class="md-input text-sm"
                            data-testid="smtp-encryption"
                        >
                            <option v-for="o in encryptionOptions" :key="o.value" :value="o.value">
                                {{ o.label }}
                            </option>
                        </select>
                        <p v-if="smtpErrors.encryption" class="mt-1 text-xs text-rose-300">{{ smtpErrors.encryption }}</p>
                    </div>

                    <div>
                        <label class="mb-1.5 block text-xs text-zinc-500" for="smtp-username">Username</label>
                        <input
                            id="smtp-username"
                            v-model="smtpForm.username"
                            class="md-input font-mono text-sm"
                            autocomplete="off"
                            data-testid="smtp-username"
                        />
                        <p v-if="smtpErrors.username" class="mt-1 text-xs text-rose-300">{{ smtpErrors.username }}</p>
                    </div>

                    <div>
                        <label class="mb-1.5 block text-xs text-zinc-500" for="smtp-password">Password</label>
                        <input
                            id="smtp-password"
                            v-model="smtpForm.password"
                            type="password"
                            class="md-input font-mono text-sm"
                            autocomplete="new-password"
                            :placeholder="
                                smtpSettings.has_password && !smtpForm.clear_password
                                    ? 'Saved (leave blank to keep it)'
                                    : 'SMTP password'
                            "
                            data-testid="smtp-password"
                        />
                        <p class="mt-1.5 text-xs text-zinc-500">
                            Stored encrypted and never shown again.
                        </p>
                        <label
                            v-if="smtpSettings.has_password"
                            class="mt-2 flex items-center gap-2 text-xs text-zinc-400"
                        >
                            <input
                                v-model="smtpForm.clear_password"
                                type="checkbox"
                                class="rounded border-zinc-700 bg-zinc-900 text-cyan-400 focus:ring-cyan-400/40"
                            />
                            Remove the saved password
                        </label>
                        <p v-if="smtpErrors.password" class="mt-1 text-xs text-rose-300">{{ smtpErrors.password }}</p>
                    </div>
                </fieldset>

                <div class="flex justify-end border-t border-zinc-800 pt-5">
                    <button
                        type="button"
                        class="md-btn-primary"
                        data-testid="smtp-save"
                        :disabled="smtpSaving || !smtpCanManage"
                        @click="saveSmtp"
                    >
                        {{ smtpSaving ? 'Saving…' : 'Save SMTP settings' }}
                    </button>
                </div>
            </section>
        </div>

        <div
            v-else-if="tab === 'signature'"
            class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_380px]"
            data-testid="signature-settings"
        >
            <div class="space-y-6">
                <div class="md-card space-y-5 p-6">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h3 class="font-medium text-white">Email signature</h3>
                            <p class="mt-1 text-sm text-zinc-400">
                                Added to the bottom of every email this workspace
                                sends: new emails, inbox replies and forwards.
                            </p>
                        </div>
                        <label class="flex shrink-0 items-center gap-2 text-sm text-zinc-200">
                            <input
                                v-model="signature.enabled"
                                type="checkbox"
                                data-testid="signature-enabled"
                                class="rounded border-zinc-700 bg-zinc-900 text-cyan-400 focus:ring-cyan-400/40"
                            />
                            On
                        </label>
                    </div>

                    <div :class="{ 'pointer-events-none opacity-50': !signature.enabled }">
                        <label class="mb-1.5 block text-xs text-zinc-500">Workspace signature</label>
                        <div class="overflow-hidden rounded-xl border border-zinc-800">
                            <WysiwygEditor
                                v-model="signature.html"
                                placeholder="Ade Tola · Customer success · Acme Mail"
                                min-height="120px"
                            />
                        </div>
                    </div>

                    <div class="space-y-3 border-t border-zinc-800 pt-5">
                        <label class="flex items-center justify-between gap-3 text-sm text-zinc-200">
                            <span>
                                Add to broadcasts
                                <span class="mt-0.5 block text-xs text-zinc-500">Placed above the unsubscribe footer</span>
                            </span>
                            <input
                                v-model="signature.broadcasts"
                                type="checkbox"
                                class="rounded border-zinc-700 bg-zinc-900 text-cyan-400 focus:ring-cyan-400/40"
                            />
                        </label>
                        <label class="flex items-center justify-between gap-3 text-sm text-zinc-200">
                            <span>
                                Add to API emails
                                <span class="mt-0.5 block text-xs text-zinc-500">Off by default so app emails stay as designed. A request can also pass <code class="font-mono">"signature": true</code></span>
                            </span>
                            <input
                                v-model="signature.api"
                                type="checkbox"
                                class="rounded border-zinc-700 bg-zinc-900 text-cyan-400 focus:ring-cyan-400/40"
                            />
                        </label>
                    </div>
                </div>

                <div class="md-card space-y-4 p-6">
                    <div>
                        <h3 class="font-medium text-white">Mailbox signatures</h3>
                        <p class="mt-1 text-sm text-zinc-400">
                            Give a mailbox its own signature. Mail sent from that
                            address uses it instead of the workspace one.
                        </p>
                    </div>
                    <p v-if="!mailboxSignatures.length" class="text-sm text-zinc-500">
                        No mailboxes yet.
                    </p>
                    <div
                        v-for="m in mailboxSignatures"
                        :key="m.id"
                        class="rounded-xl border border-zinc-800 p-4"
                    >
                        <label class="flex items-center justify-between gap-3 text-sm text-zinc-200">
                            <span class="min-w-0">
                                <span class="block truncate font-mono text-xs">{{ m.email }}</span>
                                <span class="mt-0.5 block text-xs text-zinc-500">
                                    {{ m.custom ? 'Own signature' : 'Uses the workspace signature' }}
                                </span>
                            </span>
                            <input
                                v-model="m.custom"
                                type="checkbox"
                                class="rounded border-zinc-700 bg-zinc-900 text-cyan-400 focus:ring-cyan-400/40"
                            />
                        </label>
                        <div v-if="m.custom" class="mt-3 overflow-hidden rounded-xl border border-zinc-800">
                            <WysiwygEditor
                                v-model="m.signature"
                                :placeholder="`Signature for ${m.email}`"
                                min-height="90px"
                            />
                        </div>
                    </div>
                </div>

                <div class="flex justify-end">
                    <button
                        type="button"
                        class="md-btn-primary"
                        data-testid="signature-save"
                        :disabled="signatureSaving"
                        @click="saveSignature"
                    >
                        {{ signatureSaving ? 'Saving…' : 'Save signature' }}
                    </button>
                </div>
            </div>

            <div class="md-card h-fit space-y-3 p-5 xl:sticky xl:top-6">
                <div class="flex items-center justify-between gap-2">
                    <h3 class="text-sm font-medium text-white">Preview</h3>
                    <select
                        v-if="mailboxSignatures.length"
                        v-model="previewMailbox"
                        class="md-input !w-auto !py-1 text-xs"
                    >
                        <option value="">Workspace</option>
                        <option v-for="m in mailboxSignatures" :key="m.id" :value="String(m.id)">
                            {{ m.email }}
                        </option>
                    </select>
                </div>
                <div class="overflow-hidden rounded-xl border border-zinc-800 bg-white">
                    <EmailFrame :html="signaturePreviewHtml" />
                </div>
                <p v-if="!signature.enabled" class="text-xs text-zinc-500">
                    Signatures are off, so nothing is added right now.
                </p>
            </div>
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
