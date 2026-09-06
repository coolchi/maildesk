<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { useToast } from '@/composables/useToast';
import { useComposeModal } from '@/composables/useComposeModal';
import {
    ArrowLeft,
    CheckCircle2,
    Copy,
    LoaderCircle,
    RefreshCw,
} from '@lucide/vue';

const props = defineProps({
    domain: { type: Object, required: true },
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

const statusLabel = computed(() =>
    localStatus.value.spf && localStatus.value.dkim && localStatus.value.dmarc
        ? 'verified'
        : props.domain.status === 'verified'
          ? 'verified'
          : 'pending',
);

const step = ref(statusLabel.value === 'verified' ? 3 : 2);

const dnsRows = computed(() => {
    if (props.domain.dns_rows?.length) {
        return props.domain.dns_rows;
    }

    return [
        {
            key: 'dkim',
            type: 'TXT',
            name: `resend._domainkey.${props.domain.name}`,
            value: 'p=MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQMockDkimKey…',
            label: 'DKIM',
        },
        {
            key: 'spf',
            type: 'TXT',
            name: props.domain.name,
            value: 'v=spf1 include:amazonses.com ~all',
            label: 'SPF',
        },
        {
            key: 'dmarc',
            type: 'TXT',
            name: `_dmarc.${props.domain.name}`,
            value: 'v=DMARC1; p=none;',
            label: 'DMARC',
        },
    ];
});

const copy = async (text, label) => {
    try {
        await navigator.clipboard.writeText(text);
        toast.success(`Copied ${label}.`);
    } catch {
        toast.error('Copy failed.');
    }
};

const verify = () => {
    verifying.value = true;
    toast.info('Checking DNS…');
    router.post(
        route('domains.verify', props.domain.id),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                verifying.value = false;
            },
            onSuccess: () => {
                localStatus.value = { spf: true, dkim: true, dmarc: true };
                step.value = 3;
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
                <StatusBadge :status="statusLabel" />
            </template>
        </PageHeader>

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
                @click="step = Math.min(s.n, statusLabel === 'verified' ? 3 : 2)"
            >
                {{ s.n }}. {{ s.label }}
            </button>
        </div>

        <div v-if="step === 1" class="md-card max-w-xl space-y-4 p-6">
            <h3 class="font-medium text-white">Domain ready</h3>
            <p class="text-sm text-zinc-400">
                Next, add the DNS records at your registrar so MailDesk can
                authenticate mail from
                <span class="text-zinc-200">{{ domain.name }}</span>.
            </p>
            <button type="button" class="md-btn-primary" @click="step = 2">
                Show DNS records
            </button>
        </div>

        <div v-else-if="step === 2" class="space-y-4">
            <div class="md-card p-5">
                <h3 class="font-medium text-white">Publish these records</h3>
                <p class="mt-1 text-sm text-zinc-400">
                    Propagation can take a few minutes. Click verify when ready.
                </p>
            </div>

            <div
                v-for="row in dnsRows"
                :key="row.key || row.name"
                class="md-card space-y-3 p-5"
            >
                <div class="flex items-center justify-between gap-3">
                    <div class="flex items-center gap-2">
                        <span class="text-sm font-medium text-white">{{
                            row.label || row.type
                        }}</span>
                        <span
                            v-if="localStatus[row.key]"
                            class="inline-flex items-center gap-1 text-xs text-emerald-400"
                        >
                            <CheckCircle2 :size="12" /> Detected
                        </span>
                        <span v-else class="text-xs text-zinc-500"
                            >Not detected</span
                        >
                    </div>
                    <code class="text-xs text-cyan-300">{{ row.type }}</code>
                </div>
                <div>
                    <div class="mb-1 text-[11px] uppercase text-zinc-500">
                        Name / Host
                    </div>
                    <div
                        class="flex items-center gap-2 rounded-lg border border-zinc-800 bg-black px-3 py-2"
                    >
                        <code class="flex-1 truncate text-xs text-zinc-300">{{
                            row.name
                        }}</code>
                        <button
                            type="button"
                            class="text-zinc-500 hover:text-cyan-300"
                            @click="copy(row.name, 'name')"
                        >
                            <Copy :size="14" />
                        </button>
                    </div>
                </div>
                <div>
                    <div class="mb-1 text-[11px] uppercase text-zinc-500">
                        Value
                    </div>
                    <div
                        class="flex items-center gap-2 rounded-lg border border-zinc-800 bg-black px-3 py-2"
                    >
                        <code class="flex-1 truncate text-xs text-zinc-300">{{
                            row.value
                        }}</code>
                        <button
                            type="button"
                            class="text-zinc-500 hover:text-cyan-300"
                            @click="copy(row.value, 'value')"
                        >
                            <Copy :size="14" />
                        </button>
                    </div>
                </div>
            </div>

            <div class="flex flex-wrap gap-2">
                <button
                    type="button"
                    class="md-btn-primary"
                    :disabled="verifying"
                    @click="verify"
                >
                    <LoaderCircle
                        v-if="verifying"
                        :size="16"
                        class="animate-spin"
                    />
                    <RefreshCw v-else :size="16" />
                    {{ verifying ? 'Verifying…' : 'Verify DNS' }}
                </button>
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
                <span class="text-zinc-200">{{ domain.name }}</span>.
            </p>
            <div class="flex gap-2">
                <button
                    type="button"
                    class="md-btn-primary"
                    @click="openCompose({ from: `hello@${domain.name}` })"
                >
                    Compose email
                </button>
                <Link :href="route('domains')" class="md-btn-ghost"
                    >All domains</Link
                >
            </div>
        </div>
    </AppLayout>
</template>
