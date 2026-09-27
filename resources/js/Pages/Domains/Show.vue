<script setup>
import { computed, ref, watch } from "vue";
import { Head, Link, router } from "@inertiajs/vue3";
import AppLayout from "@/Layouts/AppLayout.vue";
import PageHeader from "@/Components/PageHeader.vue";
import StatusBadge from "@/Components/StatusBadge.vue";
import DnsManager from "@/Components/DnsManager.vue";
import { useToast } from "@/composables/useToast";
import { useComposeModal } from "@/composables/useComposeModal";
import {
    AlertTriangle,
    ArrowLeft,
    CheckCircle2,
    CircleDashed,
    Copy,
    Info,
    LoaderCircle,
    RefreshCw,
    XCircle,
} from "@lucide/vue";

const props = defineProps({
    domain: { type: Object, required: true },
    dns: { type: Object, default: null },
});

const toast = useToast();
const { open: openCompose } = useComposeModal();
const verifying = ref(false);
const localStatus = ref({ ...(props.domain.records || {}) });

watch(
    () => props.domain,
    (domain) => {
        localStatus.value = { ...(domain.records || {}) };
    },
);

const statusLabel = computed(() => props.domain.status || "pending");

const checkedAt = computed(() =>
    props.domain.checked_at
        ? new Date(props.domain.checked_at).toLocaleString()
        : null,
);

// DMARC is listed as a record below, so skip its reminder banner.
const warnings = computed(() =>
    (props.domain.warnings || []).filter((w) => !/dmarc/i.test(w)),
);

const foundFor = (key) => props.domain.results?.[key]?.found || [];

const step = ref(statusLabel.value === "verified" ? 3 : 2);

const dnsRows = computed(() => {
    if (props.domain.dns_rows?.length) {
        return props.domain.dns_rows;
    }

    return [
        {
            key: "dkim",
            type: "TXT",
            name: `resend._domainkey.${props.domain.name}`,
            value: "p=MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQMockDkimKey…",
            label: "DKIM",
        },
        {
            key: "spf",
            type: "TXT",
            name: props.domain.name,
            value: "v=spf1 include:amazonses.com ~all",
            label: "SPF",
        },
        {
            key: "dmarc",
            type: "TXT",
            name: `_dmarc.${props.domain.name}`,
            value: "v=DMARC1; p=none;",
            label: "DMARC",
        },
    ];
});

const purposes = {
    dkim: "Proves mail really comes from your domain",
    spf: "Allows Resend to send for this domain",
    mx: "Receives bounce notices (return path)",
    inbound_mx: "Routes incoming mail to MailDesk",
    return_path: "Handles bounces for Resend's return path",
    dmarc: "Tells inboxes how to treat mail that fails checks",
};

const titles = {
    dkim: "DKIM",
    spf: "SPF",
    mx: "Return path (MX)",
    inbound_mx: "Receiving (MX)",
    return_path: "Return path (CNAME)",
    dmarc: "DMARC",
};

const statusMeta = {
    pass: {
        label: "Verified",
        icon: CheckCircle2,
        class: "bg-emerald-500/10 text-emerald-400",
    },
    mismatch: {
        label: "Wrong value",
        icon: AlertTriangle,
        class: "bg-amber-500/10 text-amber-400",
    },
    missing: {
        label: "Not found",
        icon: XCircle,
        class: "bg-rose-500/10 text-rose-400",
    },
    optional: {
        label: "Recommended",
        icon: CircleDashed,
        class: "bg-zinc-800 text-zinc-400",
    },
};

const expanded = ref({});

const recordStatus = (row) => {
    if (localStatus.value[row.key]) return "pass";
    if (foundFor(row.key).length) return "mismatch";
    return row.key === "dmarc" ? "optional" : "missing";
};

const rows = computed(() =>
    dnsRows.value.map((row, i) => {
        const status = recordStatus(row);
        const value = String(row.value ?? "");

        return {
            ...row,
            id: `${row.key || row.type}-${i}`,
            title: titles[row.key] || row.label || row.type,
            purpose: purposes[row.key] || "",
            status,
            value,
            long: value.length > 80,
            found: status === "mismatch" ? foundFor(row.key)[0] : null,
        };
    }),
);

const passingCount = computed(
    () => listedRows.value.filter((r) => r.status === "pass").length,
);

const groups = computed(() =>
    [
        {
            id: "passing",
            title: "Verified",
            icon: CheckCircle2,
            iconClass: "text-emerald-400",
            rows: rows.value.filter((r) => r.status === "pass"),
        },
        {
            id: "attention",
            title: "Needs attention",
            icon: AlertTriangle,
            iconClass: "text-amber-400",
            rows: rows.value.filter((r) =>
                ["mismatch", "missing"].includes(r.status),
            ),
        },
        {
            id: "optional",
            title: "Recommended",
            icon: Info,
            iconClass: "text-zinc-400",
            rows: rows.value.filter((r) => r.status === "optional"),
        },
    ].filter((g) => g.rows.length),
);

// Fallback for when the Clipboard API is missing or rejected (plain-http
// local domains aren't a secure context, or the tab isn't focused).
const legacyCopy = (text) => {
    const el = document.createElement("textarea");
    el.value = text;
    el.setAttribute("readonly", "");
    el.style.position = "fixed";
    el.style.top = "0";
    el.style.left = "0";
    el.style.opacity = "0";
    document.body.appendChild(el);
    const selection = document.getSelection();
    const previous = selection && selection.rangeCount ? selection.getRangeAt(0) : null;
    el.select();
    el.setSelectionRange(0, text.length);
    let ok = false;
    try {
        ok = document.execCommand("copy");
    } catch {
        ok = false;
    }
    document.body.removeChild(el);
    if (previous && selection) {
        selection.removeAllRanges();
        selection.addRange(previous);
    }
    return ok;
};

// Only the records actually shown in the groups count toward the summary.
const listedRows = computed(() => groups.value.flatMap((g) => g.rows));

const copy = async (text, label) => {
    try {
        if (!navigator.clipboard?.writeText) throw new Error("no clipboard api");
        await navigator.clipboard.writeText(text);
        toast.success(`Copied ${label}.`);
        return;
    } catch {
        // fall through to the legacy path
    }

    if (legacyCopy(text)) {
        toast.success(`Copied ${label}.`);
    } else {
        toast.error("Copy failed. Select the value and copy it manually.");
    }
};

const verify = () => {
    verifying.value = true;
    toast.info("Checking DNS…");
    router.post(
        route("domains.verify", props.domain.id),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                verifying.value = false;
            },
            onSuccess: (page) => {
                // Trust the server's DNS result; never assume success.
                step.value = page.props.domain?.status === "verified" ? 3 : 2;
            },
        },
    );
};
</script>

<template>
    <Head :title="`Domain · ${domain.name}`" />

    <AppLayout>
        <div class="mb-4">
            <Link
                :href="route('domains')"
                class="inline-flex items-center gap-1.5 text-sm text-zinc-500 hover:text-cyan-300"
            >
                <ArrowLeft :size="14" />
                Domains
            </Link>
        </div>

        <PageHeader :title="domain.name" description="DNS verification wizard">
            <template #actions>
                <StatusBadge
                    :status="statusLabel"
                    :loading="statusLabel === 'pending'"
                />
            </template>
        </PageHeader>

        <div
            v-if="warnings.length"
            class="mb-6 rounded-lg border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-300"
        >
            <p v-for="w in warnings" :key="w">{{ w }}</p>
        </div>

        <div class="mb-8 flex flex-wrap gap-2">
            <button
                v-for="s in [
                    { n: 1, label: 'Add domain' },
                    { n: 2, label: 'Publish DNS' },
                    { n: 3, label: 'Verified' },
                ]"
                :key="s.n"
                type="button"
                class="rounded-full px-3 py-1.5 text-xs transition"
                :class="
                    step === s.n
                        ? 'bg-cyan-400/15 text-cyan-300'
                        : step > s.n
                          ? 'bg-emerald-500/10 text-emerald-300'
                          : 'bg-zinc-900 text-zinc-500'
                "
                @click="
                    step = Math.min(s.n, statusLabel === 'verified' ? 3 : 2)
                "
            >
                {{ s.n }}. {{ s.label }}
            </button>
        </div>

        <div v-if="step === 1" class="md-card max-w-xl space-y-4 p-6">
            <h3 class="font-medium text-white">Domain ready</h3>
            <p class="text-sm text-zinc-400">
                Next, add the DNS records at your registrar so MailDesk can
                authenticate mail from
                <span class="text-zinc-200">{{ domain.name }}</span
                >.
            </p>
            <button type="button" class="md-btn-primary" @click="step = 2">
                Show DNS records
            </button>
        </div>

        <div v-else-if="step === 2" class="space-y-4">
            <div
                class="md-card flex flex-wrap items-center justify-between gap-4 p-5"
            >
                <div class="min-w-0">
                    <h3 class="font-medium text-white">
                        Add these DNS records
                    </h3>
                    <p class="mt-1 text-sm text-zinc-400">
                        Add each record at your DNS provider, then click Verify.
                        Changes can take a few minutes to show up.
                    </p>
                    <p class="mt-2 text-xs text-zinc-500">
                        <span class="font-medium text-zinc-300"
                            >{{ passingCount }} of {{ listedRows.length }}</span
                        >
                        records verified<template v-if="checkedAt">
                            · Last checked {{ checkedAt }} · Re-checked
                            automatically every hour</template
                        >
                    </p>
                    <p
                        v-if="domain.provider_error"
                        class="mt-2 text-xs text-rose-400"
                    >
                        {{ domain.provider_error }}
                    </p>
                </div>
                <button
                    type="button"
                    class="md-btn-primary shrink-0"
                    :disabled="verifying"
                    @click="verify"
                >
                    <LoaderCircle
                        v-if="verifying"
                        :size="16"
                        class="animate-spin"
                    />
                    <RefreshCw v-else :size="16" />
                    {{ verifying ? "Verifying…" : "Verify DNS" }}
                </button>
            </div>

            <DnsManager :domain="domain" :dns="dns" />

            <section
                v-for="group in groups"
                :key="group.id"
                class="md-card overflow-hidden"
                :data-testid="`dns-group-${group.id}`"
            >
                <header
                    class="flex items-center gap-2 border-b border-zinc-800 px-5 py-3"
                >
                    <component
                        :is="group.icon"
                        :size="15"
                        :class="group.iconClass"
                    />
                    <h4 class="text-sm font-medium text-white">
                        {{ group.title }}
                    </h4>
                    <span class="text-xs text-zinc-500"
                        >({{ group.rows.length }})</span
                    >
                </header>

                <div
                    class="hidden grid-cols-[9rem_4.5rem_minmax(0,1fr)_minmax(0,2fr)] gap-4 border-b border-zinc-800 px-5 py-2 text-[11px] font-medium uppercase tracking-wide text-zinc-500 md:grid"
                >
                    <span>Record</span>
                    <span>Type</span>
                    <span>Name (host)</span>
                    <span>Value</span>
                </div>

                <div
                    v-for="row in group.rows"
                    :key="row.id"
                    class="grid grid-cols-1 gap-3 border-b border-zinc-800 px-5 py-4 last:border-b-0 md:grid-cols-[9rem_4.5rem_minmax(0,1fr)_minmax(0,2fr)] md:items-start md:gap-4"
                    data-testid="dns-record-row"
                >
                    <div class="min-w-0">
                        <div class="text-sm font-medium text-white">
                            {{ row.title }}
                        </div>
                        <div class="mt-0.5 text-xs text-zinc-500">
                            {{ row.purpose }}
                        </div>
                        <span
                            class="mt-2 inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium"
                            :class="statusMeta[row.status].class"
                        >
                            <component
                                :is="statusMeta[row.status].icon"
                                :size="11"
                            />
                            {{ statusMeta[row.status].label }}
                        </span>
                    </div>

                    <div class="flex items-center gap-2 md:block">
                        <span
                            class="text-[11px] uppercase text-zinc-500 md:hidden"
                            >Type</span
                        >
                        <code class="text-xs text-cyan-300">{{
                            row.type
                        }}</code>
                        <div
                            v-if="row.priority != null"
                            class="text-[11px] text-zinc-500 md:mt-1"
                        >
                            Priority {{ row.priority }}
                        </div>
                    </div>

                    <div class="min-w-0">
                        <div
                            class="mb-1 text-[11px] uppercase text-zinc-500 md:hidden"
                        >
                            Name (host)
                        </div>
                        <div
                            class="flex items-start gap-2 rounded-lg border border-zinc-800 bg-black px-3 py-2"
                        >
                            <code
                                class="min-w-0 flex-1 break-all text-xs text-zinc-300"
                                >{{ row.name }}</code
                            >
                            <button
                                type="button"
                                class="shrink-0 text-zinc-500 hover:text-cyan-300"
                                :aria-label="`Copy ${row.title} name`"
                                @click="copy(row.name, `${row.title} name`)"
                            >
                                <Copy :size="14" />
                            </button>
                        </div>
                    </div>

                    <div class="min-w-0">
                        <div
                            class="mb-1 text-[11px] uppercase text-zinc-500 md:hidden"
                        >
                            Value
                        </div>
                        <div
                            class="flex items-start gap-2 rounded-lg border border-zinc-800 bg-black px-3 py-2"
                        >
                            <code
                                class="min-w-0 flex-1 break-all text-xs leading-5 text-zinc-300"
                                :class="
                                    row.long && !expanded[row.id]
                                        ? 'line-clamp-2'
                                        : ''
                                "
                                :title="row.value"
                                data-testid="dns-record-value"
                                >{{ row.value }}</code
                            >
                            <button
                                type="button"
                                class="shrink-0 text-zinc-500 hover:text-cyan-300"
                                :aria-label="`Copy ${row.title} value`"
                                @click="copy(row.value, `${row.title} value`)"
                            >
                                <Copy :size="14" />
                            </button>
                        </div>
                        <button
                            v-if="row.long"
                            type="button"
                            class="mt-1 text-[11px] text-cyan-300 hover:underline"
                            @click="expanded[row.id] = !expanded[row.id]"
                        >
                            {{
                                expanded[row.id]
                                    ? "Show less"
                                    : "Show full value"
                            }}
                        </button>
                        <div
                            v-if="row.found"
                            class="mt-2 rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-xs text-amber-300"
                        >
                            <div class="mb-0.5 font-medium">
                                Currently published (doesn't match):
                            </div>
                            <code
                                class="line-clamp-2 break-all"
                                :title="row.found"
                                >{{ row.found }}</code
                            >
                        </div>
                    </div>
                </div>
            </section>

            <div class="flex flex-wrap gap-2">
                <button type="button" class="md-btn-ghost" @click="step = 1">
                    Back
                </button>
            </div>
        </div>

        <div v-else class="md-card max-w-xl space-y-4 p-6">
            <div
                class="flex h-12 w-12 items-center justify-center rounded-xl bg-emerald-500/15 text-emerald-400"
            >
                <CheckCircle2 :size="24" />
            </div>
            <h3 class="text-lg font-medium text-white">Domain verified</h3>
            <p class="text-sm text-zinc-400">
                You can now send from addresses on
                <span class="text-zinc-200">{{ domain.name }}</span
                >.
            </p>
            <div class="flex gap-2">
                <button
                    type="button"
                    class="md-btn-primary"
                    @click="openCompose({ from: `hello@${domain.name}` })"
                >
                    Compose email
                </button>
                <button
                    type="button"
                    class="md-btn-ghost"
                    :disabled="verifying"
                    @click="verify"
                >
                    <LoaderCircle
                        v-if="verifying"
                        :size="16"
                        class="animate-spin"
                    />
                    <RefreshCw v-else :size="16" />
                    {{ verifying ? "Checking…" : "Re-check DNS" }}
                </button>
                <Link :href="route('domains')" class="md-btn-ghost"
                    >All domains</Link
                >
            </div>
            <p v-if="checkedAt" class="text-xs text-zinc-500">
                Last checked {{ checkedAt }}.
            </p>
        </div>
    </AppLayout>
</template>
