<script setup>
import { ref, watch } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import EmptyState from '@/Components/EmptyState.vue';
import Modal from '@/Components/Modal.vue';
import { useToast } from '@/composables/useToast';
import { Ban, ShieldAlert } from '@lucide/vue';

const props = defineProps({
    entries: { type: Array, default: () => [] },
    currentIp: { type: String, default: '' },
    enabled: { type: Boolean, default: false },
    error: { type: String, default: null },
});

const page = usePage();
const toast = useToast();
const pending = ref(null);
const busy = ref(false);

watch(
    () => page.props.flash?.success,
    (message) => {
        if (message) {
            toast.success(message);
        }
    },
    { immediate: true },
);

watch(
    () => page.props.flash?.error,
    (message) => {
        if (message) {
            toast.error(message, 6000);
        }
    },
    { immediate: true },
);

const formatCreated = (value) => {
    if (!value) {
        return '—';
    }

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return date.toLocaleString();
};

const confirmUnblock = (entry) => {
    pending.value = entry;
};

const submitUnblock = () => {
    if (!pending.value || busy.value) {
        return;
    }

    busy.value = true;
    router.post(
        route('admin.blocked-ips.unblock'),
        { ip: pending.value.ip },
        {
            preserveScroll: true,
            onFinish: () => {
                busy.value = false;
                pending.value = null;
            },
        },
    );
};
</script>

<template>
    <Head title="Admin · Blocked IPs" />

    <AdminLayout>
        <PageHeader
            title="Blocked IPs"
            description="Addresses Stalwart auto-banned after too many failed logins. Unblock an IP to let mail clients connect again."
        />

        <div
            v-if="error"
            class="mb-4 rounded-lg border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-100"
        >
            {{ error }}
        </div>

        <EmptyState
            v-if="!entries.length && !error"
            title="No blocked IPs"
            description="Stalwart has not auto-banned any addresses."
        >
            <template #icon>
                <Ban :size="28" :stroke-width="1.5" />
            </template>
        </EmptyState>

        <div v-else-if="entries.length" class="md-table-wrap">
            <table class="min-w-full text-left text-sm">
                <thead class="border-b border-zinc-800 text-xs uppercase tracking-wide text-zinc-500">
                    <tr>
                        <th class="px-4 py-3 font-medium">IP address</th>
                        <th class="px-4 py-3 font-medium">Reason</th>
                        <th class="px-4 py-3 font-medium">Blocked</th>
                        <th class="px-4 py-3 font-medium text-right"> </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-900">
                    <tr
                        v-for="entry in entries"
                        :key="entry.id"
                        class="transition hover:bg-zinc-900/50"
                        :class="entry.is_current ? 'bg-amber-500/5' : ''"
                    >
                        <td class="px-4 py-3">
                            <code class="font-mono text-sm text-white">{{ entry.ip }}</code>
                            <span
                                v-if="entry.is_current"
                                class="ml-2 inline-flex items-center gap-1 rounded-full bg-amber-500/15 px-2 py-0.5 text-[11px] font-medium text-amber-200"
                            >
                                <ShieldAlert :size="12" />
                                This is your IP
                            </span>
                        </td>
                        <td class="px-4 py-3 text-zinc-300">
                            {{ entry.reason_label }}
                            <span v-if="entry.reason" class="block text-[11px] text-zinc-500">
                                {{ entry.reason }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-zinc-400">
                            {{ formatCreated(entry.created) }}
                        </td>
                        <td class="px-4 py-3 text-right">
                            <button
                                type="button"
                                class="md-btn-ghost"
                                :disabled="busy"
                                @click="confirmUnblock(entry)"
                            >
                                Unblock
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p v-if="currentIp" class="mt-3 text-xs text-zinc-500">
            Your current IP is <code class="font-mono text-zinc-400">{{ currentIp }}</code>.
        </p>

        <Modal
            :show="!!pending"
            title="Unblock this IP?"
            :description="
                pending
                    ? `Unblock ${pending.ip}? Mail clients from this address will be able to connect again.`
                    : ''
            "
            @close="pending = null"
        >
            <template #footer>
                <button type="button" class="md-btn-ghost" @click="pending = null">Cancel</button>
                <button
                    type="button"
                    class="md-btn-primary"
                    :disabled="busy"
                    @click="submitUnblock"
                >
                    {{ busy ? 'Unblocking…' : 'Unblock' }}
                </button>
            </template>
        </Modal>
    </AdminLayout>
</template>
