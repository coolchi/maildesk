<script setup>
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { useComposeModal } from '@/composables/useComposeModal';
import { Code2, Download, Search } from '@lucide/vue';

const props = defineProps({
    emails: { type: Array, default: () => [] },
    stats: {
        type: Object,
        default: () => ({
            sent: 0,
            delivered: 0,
            bounced: 0,
            received: 0,
        }),
    },
});

const { open: openCompose } = useComposeModal();
const tab = ref('sending');
const search = ref('');
const status = ref('all');

const filtered = computed(() => {
    return props.emails.filter((email) => {
        const directionOk =
            tab.value === 'sending'
                ? email.direction === 'outbound'
                : email.direction === 'inbound';
        const statusOk = status.value === 'all' || email.status === status.value;
        const q = search.value.trim().toLowerCase();
        const searchOk =
            !q ||
            email.to?.toLowerCase().includes(q) ||
            email.subject?.toLowerCase().includes(q) ||
            email.from?.toLowerCase().includes(q);
        return directionOk && statusOk && searchOk;
    });
});
</script>

<template>
    <Head title="Emails" />

    <AppLayout>
        <PageHeader
            title="Emails"
            description="Transactional and inbound mail across your workspace."
        >
            <template #actions>
                <Link :href="route('suppressions')" class="md-btn-ghost">Suppressions</Link>
                <Link :href="route('docs')" class="md-btn-ghost" title="API">
                    <Code2 :size="16" />
                </Link>
                <button type="button" class="md-btn-solid" @click="openCompose()">
                    Send email
                </button>
            </template>
        </PageHeader>

        <div class="mb-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div
                v-for="card in [
                    { label: 'Sent (15d)', value: stats.sent },
                    { label: 'Delivered', value: stats.delivered },
                    { label: 'Bounced', value: stats.bounced },
                    { label: 'Received', value: stats.received },
                ]"
                :key="card.label"
                class="md-card px-4 py-3"
            >
                <div class="text-xs uppercase tracking-wide text-zinc-500">
                    {{ card.label }}
                </div>
                <div class="mt-1 text-2xl font-semibold tabular-nums text-white">
                    {{ Number(card.value || 0).toLocaleString() }}
                </div>
            </div>
        </div>

        <div class="mb-4 inline-flex rounded-full border border-zinc-800 bg-zinc-950 p-1">
            <button
                type="button"
                class="rounded-full px-4 py-1.5 text-sm transition"
                :class="
                    tab === 'sending'
                        ? 'bg-zinc-800 text-white'
                        : 'text-zinc-400 hover:text-zinc-200'
                "
                @click="tab = 'sending'"
            >
                Sending
            </button>
            <button
                type="button"
                class="rounded-full px-4 py-1.5 text-sm transition"
                :class="
                    tab === 'receiving'
                        ? 'bg-zinc-800 text-white'
                        : 'text-zinc-400 hover:text-zinc-200'
                "
                @click="tab = 'receiving'"
            >
                Receiving
            </button>
        </div>

        <div class="mb-4 flex flex-col gap-3 lg:flex-row lg:items-center">
            <div class="relative grow">
                <Search
                    :size="16"
                    class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-zinc-500"
                />
                <input
                    v-model="search"
                    type="search"
                    placeholder="Search…"
                    class="md-input pl-9"
                />
            </div>
            <select v-model="status" class="md-input lg:w-44">
                <option value="all">All statuses</option>
                <option value="delivered">Delivered</option>
                <option value="bounced">Bounced</option>
                <option value="suppressed">Suppressed</option>
                <option value="received">Received</option>
            </select>
            <select class="md-input lg:w-44">
                <option>Last 15 days</option>
                <option>Last 7 days</option>
                <option>Last 30 days</option>
            </select>
            <select class="md-input lg:w-44">
                <option>All API keys</option>
            </select>
            <button type="button" class="md-btn-ghost" title="Export">
                <Download :size="16" />
            </button>
        </div>

        <div class="md-table-wrap">
            <table class="min-w-full text-left text-sm">
                <thead
                    class="border-b border-zinc-800 text-xs uppercase tracking-wide text-zinc-500"
                >
                    <tr>
                        <th class="px-4 py-3 font-medium">
                            {{ tab === 'sending' ? 'To' : 'From' }}
                        </th>
                        <th class="px-4 py-3 font-medium">Status</th>
                        <th class="px-4 py-3 font-medium">Subject</th>
                        <th class="px-4 py-3 font-medium">Sent</th>
                        <th class="px-4 py-3" />
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-900">
                    <tr
                        v-for="email in filtered"
                        :key="email.id"
                        class="transition hover:bg-white/[0.03]"
                    >
                        <td class="px-4 py-3">
                            <Link
                                :href="route('emails.show', email.id)"
                                class="font-medium text-zinc-200 hover:text-cyan-300"
                            >
                                {{
                                    tab === 'sending' ? email.to : email.from
                                }}
                            </Link>
                        </td>
                        <td class="px-4 py-3">
                            <StatusBadge :status="email.status" />
                        </td>
                        <td class="max-w-md truncate px-4 py-3 text-zinc-400">
                            <Link
                                :href="route('emails.show', email.id)"
                                class="hover:text-zinc-200"
                            >
                                {{ email.subject }}
                            </Link>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-zinc-500">
                            {{ email.sent }}
                        </td>
                        <td class="px-4 py-3 text-right text-zinc-500">⋯</td>
                    </tr>
                    <tr v-if="!filtered.length">
                        <td
                            colspan="5"
                            class="px-4 py-12 text-center text-zinc-500"
                        >
                            No emails match your filters.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>
