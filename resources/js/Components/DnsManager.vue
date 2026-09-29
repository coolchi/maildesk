<script setup>
import { computed, reactive, ref, watch } from "vue";
import { router, useForm, usePage } from "@inertiajs/vue3";
import {
    AlertTriangle,
    CheckCircle2,
    Clock,
    Cloud,
    ExternalLink,
    Inbox,
    LoaderCircle,
    Pencil,
    Plus,
    Trash2,
    Unplug,
    Wand2,
} from "@lucide/vue";

const props = defineProps({
    domain: { type: Object, required: true },
    dns: { type: Object, default: () => ({ connection: null, records: [], live: [] }) },
});

const page = usePage();
const connectForm = useForm({ provider: "cloudflare", api_token: "" });
const busy = ref(null);
const editing = reactive({});
const showReceivingConfirm = ref(false);
const receivingConfirmData = ref(null);

watch(
    () => page.props.flash?.receiving_confirmation,
    (confirmation) => {
        if (confirmation?.required) {
            receivingConfirmData.value = confirmation;
            showReceivingConfirm.value = true;
        }
    },
    { immediate: true },
);

const titles = { dkim: "DKIM", mx: "Bounce MX", spf: "SPF", inbound_mx: "Receiving MX", return_path: "Return CNAME", dmarc: "DMARC" };
const stateMeta = {
    ok: { label: "Published", class: "bg-emerald-500/10 text-emerald-300" },
    missing: { label: "Not at Cloudflare", class: "bg-rose-500/10 text-rose-300" },
    different: { label: "Different value", class: "bg-amber-500/10 text-amber-300" },
    skipped: { label: "Skipped (existing MX)", class: "bg-amber-500/10 text-amber-300" },
};

const records = computed(() => props.dns?.records || []);
const live = computed(() => props.dns?.live || []);
const pending = computed(() => records.value.filter((r) => r.state !== "ok" && r.key !== "inbound_mx").length);
const autoPublish = computed(() => props.dns?.auto_publish || null);
const autoPublishedAt = computed(() =>
    autoPublish.value?.at ? new Date(autoPublish.value.at).toLocaleString() : null,
);
const skippedInboundMx = computed(() => autoPublish.value?.skipped_inbound_mx || false);
const inboundMxRecord = computed(() => records.value.find((r) => r.key === "inbound_mx"));
const inboundMxMissing = computed(() => inboundMxRecord.value?.state === "missing");
const inboundMxNotConfigured = computed(() => !inboundMxRecord.value);

const opts = (key) => ({
    preserveScroll: true,
    onStart: () => (busy.value = key),
    onFinish: () => (busy.value = null),
});

const connect = () =>
    connectForm.post(route("domains.dns.connect", props.domain.id), {
        preserveScroll: true,
        onSuccess: () => connectForm.reset("api_token"),
    });

const disconnect = () => {
    if (!confirm("Disconnect Cloudflare? Records already published stay as they are.")) return;
    router.delete(route("domains.dns.disconnect", props.domain.id), opts("disconnect"));
};

const apply = (key = null) =>
    router.post(route("domains.dns.apply", props.domain.id), key ? { key } : {}, opts(key || "all"));

const enableReceiving = (confirm = false) => {
    showReceivingConfirm.value = false;
    receivingConfirmData.value = null;
    router.post(route("domains.dns.enable-receiving", props.domain.id), confirm ? { confirm: true } : {}, opts("inbound_mx"));
};

const startEdit = (rec) => {
    editing[rec.id] = { content: rec.content, priority: rec.priority };
};

const saveEdit = (rec) =>
    router.put(
        route("domains.dns.records.update", [props.domain.id, rec.id]),
        editing[rec.id],
        { ...opts(rec.id), onSuccess: () => delete editing[rec.id] },
    );

const remove = (rec) => {
    if (!confirm(`Delete this ${rec.type} record for ${rec.name} at Cloudflare?`)) return;
    router.delete(route("domains.dns.records.destroy", [props.domain.id, rec.id]), opts(rec.id));
};
</script>

<template>
    <section class="md-card overflow-hidden" data-testid="dns-manager">
        <header class="flex flex-wrap items-center justify-between gap-3 border-b border-zinc-800 px-5 py-4">
            <div class="flex items-center gap-2">
                <Cloud :size="16" class="text-orange-400" />
                <h4 class="text-sm font-medium text-white">Manage DNS from MailDesk</h4>
                <span
                    v-if="dns?.connection"
                    class="rounded-full bg-emerald-500/10 px-2 py-0.5 text-[11px] font-medium text-emerald-300"
                    >Connected · {{ dns.connection.zone_name }}</span
                >
            </div>
            <div v-if="dns?.connection" class="flex flex-wrap gap-2">
                <button
                    type="button"
                    class="md-btn-primary"
                    :disabled="!pending || busy"
                    @click="apply()"
                >
                    <LoaderCircle v-if="busy === 'all'" :size="15" class="animate-spin" />
                    <Wand2 v-else :size="15" />
                    {{ pending ? `Publish ${pending} record${pending > 1 ? "s" : ""}` : "All records published" }}
                </button>
                <button type="button" class="md-btn-ghost" :disabled="busy" @click="disconnect">
                    <Unplug :size="15" /> Disconnect
                </button>
            </div>
        </header>

        <!-- Not connected -->
        <form v-if="!dns?.connection" class="grid gap-4 p-5 md:grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)]" @submit.prevent="connect">
            <div class="text-sm text-zinc-400">
                <p>
                    Is <span class="text-zinc-200">{{ domain.name }}</span> on Cloudflare? Connect it and
                    MailDesk adds every record below for you, keeps them correct, and verifies the domain.
                    Nothing to copy by hand.
                </p>
                <p class="mt-3 text-xs text-zinc-500">
                    Create an API token in Cloudflare under My Profile, API Tokens, using the
                    <span class="text-zinc-300">Edit zone DNS</span> template for this zone. MailDesk only
                    touches mail records (MX, SPF, DKIM, DMARC, return-path CNAME) at the hosts listed here. The token is
                    stored encrypted.
                </p>
                <a
                    href="https://dash.cloudflare.com/profile/api-tokens"
                    target="_blank"
                    rel="noopener"
                    class="mt-2 inline-flex items-center gap-1 text-xs text-cyan-300 hover:underline"
                    >Open Cloudflare API tokens <ExternalLink :size="12"
                /></a>
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-zinc-400" for="cf-token">Cloudflare API token</label>
                <input
                    id="cf-token"
                    v-model="connectForm.api_token"
                    type="password"
                    autocomplete="off"
                    class="md-input w-full"
                    placeholder="Paste a token with Zone · DNS · Edit"
                />
                <p v-if="connectForm.errors.api_token" class="mt-2 text-xs text-rose-400">
                    {{ connectForm.errors.api_token }}
                </p>
                <button type="submit" class="md-btn-primary mt-3" :disabled="connectForm.processing || !connectForm.api_token">
                    <LoaderCircle v-if="connectForm.processing" :size="15" class="animate-spin" />
                    <Cloud v-else :size="15" />
                    Connect Cloudflare
                </button>
                <p class="mt-3 text-[11px] text-zinc-600">More DNS providers (Route 53, GoDaddy, Namecheap) are planned.</p>
            </div>
        </form>

        <!-- Connected -->
        <template v-else>
            <p v-if="dns.error" class="border-b border-zinc-800 px-5 py-3 text-sm text-rose-400">{{ dns.error }}</p>
            <p v-if="autoPublish?.error" class="border-b border-zinc-800 px-5 py-3 text-sm text-rose-400" data-testid="auto-publish-error">
                Automatic publishing failed: {{ autoPublish.error }}
            </p>
            <p class="border-b border-zinc-800 px-5 py-3 text-xs text-zinc-500" data-testid="auto-publish-note">
                MailDesk publishes these records at Cloudflare automatically and fixes them if they drift, every
                time the domain is checked.
                <span v-if="autoPublishedAt">Last run {{ autoPublishedAt }}.</span>
            </p>

            <!-- Receiving MX not configured yet (Resend hasn't issued the record) -->
            <div
                v-if="inboundMxNotConfigured"
                class="border-b border-zinc-800 bg-zinc-800/50 px-5 py-4"
                data-testid="receiving-mx-pending"
            >
                <div class="flex items-center gap-3">
                    <Clock :size="16" class="text-zinc-400 shrink-0" />
                    <div class="text-sm text-zinc-400">
                        <span class="font-medium text-zinc-300">Receiving MX</span> —
                        Resend has not issued a receiving MX record for this domain yet.
                        Re-verify the domain after a few minutes, or contact support if this persists.
                    </div>
                </div>
            </div>

            <!-- Receiving MX section (skipped or missing) -->
            <div
                v-else-if="inboundMxRecord && (skippedInboundMx || inboundMxMissing)"
                class="border-b border-zinc-800 bg-amber-500/5 px-5 py-4"
                data-testid="receiving-mx-section"
            >
                <div class="flex flex-wrap items-start gap-4">
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2 text-sm font-medium text-amber-300">
                            <AlertTriangle :size="16" />
                            Receiving not enabled
                        </div>
                        <p class="mt-1 text-xs text-zinc-400">
                            <span v-if="skippedInboundMx">
                                The receiving MX record was not published automatically because
                                <span class="text-zinc-200">{{ domain.name }}</span> already has MX records.
                                Enabling receiving adds MailDesk's MX record alongside the existing ones. Depending on MX priorities, this may change where email is delivered.
                            </span>
                            <span v-else>
                                Add the receiving MX record to receive email at
                                <span class="text-zinc-200">{{ domain.name }}</span>.
                            </span>
                        </p>
                    </div>
                    <div class="shrink-0">
                        <button
                            v-if="!showReceivingConfirm"
                            type="button"
                            class="md-btn-primary text-xs"
                            data-testid="enable-receiving"
                            :disabled="busy"
                            @click="skippedInboundMx ? (showReceivingConfirm = true) : enableReceiving()"
                        >
                            <LoaderCircle v-if="busy === 'inbound_mx'" :size="14" class="animate-spin" />
                            <Inbox v-else :size="14" />
                            Enable receiving
                        </button>
                        <div v-else class="space-y-2 text-right" data-testid="receiving-confirm">
                            <p class="text-xs text-amber-300 max-w-xs">
                                {{ receivingConfirmData?.message || `${domain.name} already has MX records. Adding MailDesk's receiving MX may change where email is delivered, depending on MX priorities.` }}
                            </p>
                            <div class="flex gap-2 justify-end">
                                <button type="button" class="md-btn-ghost text-xs" @click="showReceivingConfirm = false; receivingConfirmData = null">
                                    Cancel
                                </button>
                                <button
                                    type="button"
                                    class="md-btn-primary text-xs"
                                    data-testid="confirm-receiving"
                                    :disabled="busy"
                                    @click="enableReceiving(true)"
                                >
                                    <LoaderCircle v-if="busy === 'inbound_mx'" :size="14" class="animate-spin" />
                                    Confirm & enable
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div
                v-for="row in records"
                :key="row.key"
                class="flex flex-wrap items-center gap-3 border-b border-zinc-800 px-5 py-3"
                data-testid="dns-plan-row"
            >
                <div class="w-28 shrink-0 text-sm font-medium text-white">{{ titles[row.key] || row.key }}</div>
                <code class="w-12 shrink-0 text-xs text-cyan-300">{{ row.type }}</code>
                <code class="min-w-0 flex-1 break-all text-xs text-zinc-400">{{ row.host }}</code>
                <span
                    class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                    :class="row.key === 'inbound_mx' && row.state === 'missing' && skippedInboundMx
                        ? stateMeta.skipped.class
                        : stateMeta[row.state].class"
                >
                    <CheckCircle2 v-if="row.state === 'ok'" :size="11" class="-mt-px mr-0.5 inline" />
                    <AlertTriangle v-else-if="row.key === 'inbound_mx' && row.state === 'missing' && skippedInboundMx" :size="11" class="-mt-px mr-0.5 inline" />
                    {{ row.key === 'inbound_mx' && row.state === 'missing' && skippedInboundMx
                        ? stateMeta.skipped.label
                        : stateMeta[row.state].label }}
                </span>
                <button
                    v-if="row.state !== 'ok' && !(row.key === 'inbound_mx' && skippedInboundMx)"
                    type="button"
                    class="md-btn-ghost text-xs"
                    :disabled="busy"
                    @click="row.key === 'inbound_mx' ? enableReceiving() : apply(row.key)"
                >
                    <LoaderCircle v-if="busy === row.key" :size="13" class="animate-spin" />
                    <Plus v-else :size="13" />
                    {{ row.key === 'inbound_mx' ? 'Enable receiving' : row.state === "missing" ? "Add" : row.key === "spf" ? "Merge into SPF" : "Fix value" }}
                </button>
            </div>

            <div class="px-5 pb-1 pt-4 text-[11px] font-medium uppercase tracking-wide text-zinc-500">
                Live mail records at Cloudflare
            </div>
            <p v-if="!live.length" class="px-5 pb-4 text-sm text-zinc-500">None yet at these hosts.</p>
            <div
                v-for="rec in live"
                :key="rec.id"
                class="flex flex-wrap items-start gap-3 border-t border-zinc-800/60 px-5 py-3 first-of-type:border-t-0"
            >
                <code class="w-12 shrink-0 pt-1 text-xs text-cyan-300">{{ rec.type }}</code>
                <code class="w-44 shrink-0 break-all pt-1 text-xs text-zinc-400">{{ rec.name }}</code>
                <div class="min-w-0 flex-1">
                    <template v-if="editing[rec.id]">
                        <div class="flex flex-wrap gap-2">
                            <input
                                v-if="rec.type === 'MX'"
                                v-model.number="editing[rec.id].priority"
                                type="number"
                                class="md-input w-20"
                                aria-label="Priority"
                            />
                            <input v-model="editing[rec.id].content" class="md-input min-w-0 flex-1 font-mono text-xs" />
                        </div>
                        <div class="mt-2 flex gap-2">
                            <button type="button" class="md-btn-primary text-xs" :disabled="busy" @click="saveEdit(rec)">Save</button>
                            <button type="button" class="md-btn-ghost text-xs" @click="delete editing[rec.id]">Cancel</button>
                        </div>
                    </template>
                    <code v-else class="line-clamp-2 break-all text-xs text-zinc-300"
                        ><span v-if="rec.priority != null" class="text-zinc-500">{{ rec.priority }} </span>{{ rec.content }}</code
                    >
                </div>
                <div v-if="!editing[rec.id]" class="flex shrink-0 gap-1">
                    <button type="button" class="md-btn-ghost p-1.5" :aria-label="`Edit ${rec.type} ${rec.name}`" @click="startEdit(rec)">
                        <Pencil :size="13" />
                    </button>
                    <button type="button" class="md-btn-ghost p-1.5 hover:text-rose-400" :aria-label="`Delete ${rec.type} ${rec.name}`" :disabled="busy" @click="remove(rec)">
                        <Trash2 :size="13" />
                    </button>
                </div>
            </div>
        </template>
    </section>
</template>
