<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import { CircleHelp } from '@lucide/vue';

const props = defineProps({
    stats: {
        type: Object,
        default: () => ({
            emails: 0,
            sent: 0,
            delivered: 0,
            bounced: 0,
            complained: 0,
            failed: 0,
            opened: 0,
            clicked: 0,
            deliverability: 0,
            bounce_rate: 0,
            complaint_rate: 0,
            open_rate: null,
            click_rate: null,
        }),
    },
    series: { type: Array, default: () => [] },
    bounceSeries: { type: Array, default: () => [] },
    complaintSeries: { type: Array, default: () => [] },
    tracking: { type: Object, default: () => ({ opens: false, clicks: false }) },
    broadcasts: { type: Array, default: () => [] },
    filters: {
        type: Object,
        default: () => ({ days: 15, domain: null, tag: null }),
    },
    domainOptions: { type: Array, default: () => [] },
    tagOptions: { type: Array, default: () => [] },
    rangeOptions: { type: Array, default: () => [7, 15, 30, 90] },
});

const hover = ref(null);
const eventFilter = ref('all');

const domain = ref(props.filters.domain || '');
const tag = ref(props.filters.tag || '');
const days = ref(props.filters.days || 15);

const applyFilters = () => {
    router.get(
        route('metrics'),
        {
            days: days.value,
            domain: domain.value || undefined,
            tag: tag.value || undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
};

const metricPoints = computed(() => props.series);
const bounceSeries = computed(() => props.bounceSeries);
const complaintSeries = computed(() => props.complaintSeries);

const visible = (key) => eventFilter.value === 'all' || eventFilter.value === key;

const maxY = computed(() => {
    const vals = metricPoints.value.flatMap((p) =>
        ['delivered', 'bounced', 'pending'].filter(visible).map((k) => p[k]),
    );
    return Math.max(...vals, 1);
});

const chartW = 640;
const chartH = 220;
const pad = 16;

const pointsFor = (key) => {
    const points = metricPoints.value;
    if (points.length < 2) return '';
    return points
        .map((p, i) => {
            const x = pad + (i / (points.length - 1)) * (chartW - pad * 2);
            const y =
                chartH - pad - (p[key] / maxY.value) * (chartH - pad * 2);
            return `${x},${y}`;
        })
        .join(' ');
};

const bounceMax = computed(() => Math.max(...bounceSeries.value, 1));
const complaintMax = computed(() => Math.max(...complaintSeries.value, 1));

const pct = (value) => (value === null || value === undefined ? null : `${value}%`);
</script>

<template>
    <Head title="Metrics" />

    <AppLayout>
        <PageHeader
            title="Metrics"
            description="Delivery health from provider events for the selected window."
        >
            <template #actions>
                <select
                    v-model="domain"
                    class="md-input !w-auto !rounded-full"
                    aria-label="Domain"
                    @change="applyFilters"
                >
                    <option value="">All domains</option>
                    <option v-for="d in domainOptions" :key="d" :value="d">
                        {{ d }}
                    </option>
                </select>
                <select
                    v-if="tagOptions.length"
                    v-model="tag"
                    class="md-input !w-auto !rounded-full"
                    aria-label="Tag"
                    @change="applyFilters"
                >
                    <option value="">All tags</option>
                    <option v-for="t in tagOptions" :key="t" :value="t">
                        {{ t }}
                    </option>
                </select>
                <select
                    v-model.number="days"
                    class="md-input !w-auto !rounded-full"
                    aria-label="Date range"
                    @change="applyFilters"
                >
                    <option v-for="r in rangeOptions" :key="r" :value="r">
                        Last {{ r }} days
                    </option>
                </select>
            </template>
        </PageHeader>

        <div class="md-card p-5 sm:p-6">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div class="flex flex-wrap gap-10">
                    <div>
                        <div class="text-[11px] uppercase tracking-wide text-zinc-500">
                            Emails sent
                        </div>
                        <div class="mt-1 text-3xl font-semibold text-white">
                            {{ stats.sent }}
                        </div>
                        <div v-if="stats.failed" class="mt-1 text-xs text-rose-400">
                            {{ stats.failed }} failed to send
                        </div>
                    </div>
                    <div>
                        <div class="text-[11px] uppercase tracking-wide text-zinc-500">
                            Delivered
                        </div>
                        <div class="mt-1 text-3xl font-semibold text-white">
                            {{ stats.delivered }}
                        </div>
                    </div>
                    <div>
                        <div class="text-[11px] uppercase tracking-wide text-zinc-500">
                            Deliverability rate
                        </div>
                        <div class="mt-1 text-3xl font-semibold text-white">
                            {{ stats.deliverability }}%
                        </div>
                    </div>
                </div>
                <select v-model="eventFilter" class="md-input !w-auto !rounded-full self-start">
                    <option value="all">All events</option>
                    <option value="delivered">Delivered</option>
                    <option value="bounced">Bounced</option>
                    <option value="pending">Awaiting delivery</option>
                </select>
            </div>

            <div class="relative mt-6 overflow-x-auto">
                <svg
                    :viewBox="`0 0 ${chartW} ${chartH}`"
                    class="h-56 w-full min-w-[480px]"
                    @mouseleave="hover = null"
                >
                    <polyline
                        v-if="visible('delivered')"
                        fill="none"
                        stroke="#34d399"
                        stroke-width="2"
                        :points="pointsFor('delivered')"
                    />
                    <polyline
                        v-if="visible('bounced')"
                        fill="none"
                        stroke="#f43f5e"
                        stroke-width="2"
                        :points="pointsFor('bounced')"
                    />
                    <polyline
                        v-if="visible('pending')"
                        fill="none"
                        stroke="rgb(34 211 238)"
                        stroke-width="2"
                        :points="pointsFor('pending')"
                    />
                    <g
                        v-for="(p, i) in metricPoints"
                        :key="p.day"
                        @mouseenter="hover = i"
                    >
                        <rect
                            :x="
                                pad +
                                (i / Math.max(metricPoints.length - 1, 1)) *
                                    (chartW - pad * 2) -
                                6
                            "
                            :y="0"
                            width="12"
                            :height="chartH"
                            fill="transparent"
                        />
                    </g>
                </svg>

                <div
                    v-if="hover !== null && metricPoints[hover]"
                    class="pointer-events-none absolute left-1/2 top-4 z-10 w-52 -translate-x-1/2 rounded-lg border border-zinc-700 bg-zinc-950 p-3 text-xs shadow-xl"
                >
                    <div class="mb-2 font-medium text-white">
                        {{ metricPoints[hover].day }}
                    </div>
                    <div class="space-y-1 text-zinc-400">
                        <div class="flex justify-between">
                            <span class="text-emerald-400">Delivered</span>
                            <span>{{ metricPoints[hover].delivered }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-rose-400">Bounced</span>
                            <span>{{ metricPoints[hover].bounced }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-cyan-300">Awaiting delivery</span>
                            <span>{{ metricPoints[hover].pending }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="mt-4 flex flex-wrap items-center gap-4 text-xs text-zinc-500">
                <span class="text-zinc-400">Last {{ metricPoints.length }} days</span>
                <span class="inline-flex items-center gap-1.5">
                    <i class="h-2 w-2 rounded-full bg-emerald-400" />
                    {{ stats.delivered }} delivered
                </span>
                <span class="inline-flex items-center gap-1.5">
                    <i class="h-2 w-2 rounded-full bg-rose-400" />
                    {{ stats.bounced }} bounced
                </span>
                <span class="inline-flex items-center gap-1.5">
                    <i class="h-2 w-2 rounded-full bg-cyan-300" />
                    Accepted by the provider, no delivery event yet
                </span>
            </div>
        </div>

        <div class="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <div class="md-card p-5">
                <div
                    class="flex items-center gap-1.5 text-[11px] uppercase tracking-wide text-zinc-500"
                    title="Hard bounces ÷ emails sent"
                >
                    Bounce rate
                    <CircleHelp :size="12" />
                </div>
                <div class="mt-1 text-3xl font-semibold text-white">
                    {{ stats.bounce_rate }}%
                </div>
                <div class="mt-6 flex h-16 items-end gap-1">
                    <div
                        v-for="(v, i) in bounceSeries"
                        :key="i"
                        class="flex-1 rounded-t bg-rose-500/70"
                        :style="{ height: Math.max((v / bounceMax) * 100, 4) + '%' }"
                    />
                </div>
            </div>
            <div class="md-card p-5">
                <div
                    class="flex items-center gap-1.5 text-[11px] uppercase tracking-wide text-zinc-500"
                    title="Spam complaints ÷ delivered"
                >
                    Complaint rate
                    <CircleHelp :size="12" />
                </div>
                <div class="mt-1 text-3xl font-semibold text-white">
                    {{ stats.complaint_rate }}%
                </div>
                <div class="mt-6 flex h-16 items-end gap-1">
                    <div
                        v-for="(v, i) in complaintSeries"
                        :key="i"
                        class="flex-1 rounded-t bg-amber-500/70"
                        :style="{ height: Math.max((v / complaintMax) * 100, 4) + '%' }"
                    />
                </div>
            </div>
            <div class="md-card p-5">
                <div
                    class="flex items-center gap-1.5 text-[11px] uppercase tracking-wide text-zinc-500"
                    title="Delivered emails with at least one open event"
                >
                    Open rate
                    <CircleHelp :size="12" />
                </div>
                <template v-if="tracking.opens">
                    <div class="mt-1 text-3xl font-semibold text-white">
                        {{ pct(stats.open_rate) }}
                    </div>
                    <div class="mt-2 text-xs text-zinc-500">
                        {{ stats.opened }} opened
                    </div>
                </template>
                <template v-else>
                    <div class="mt-1 text-xl font-semibold text-zinc-500">
                        Not tracked
                    </div>
                    <div class="mt-2 text-xs text-zinc-500">
                        No open events received yet. Enable open tracking with
                        your provider.
                    </div>
                </template>
            </div>
            <div class="md-card p-5">
                <div
                    class="flex items-center gap-1.5 text-[11px] uppercase tracking-wide text-zinc-500"
                    title="Delivered emails with at least one click event"
                >
                    Click rate
                    <CircleHelp :size="12" />
                </div>
                <template v-if="tracking.clicks">
                    <div class="mt-1 text-3xl font-semibold text-white">
                        {{ pct(stats.click_rate) }}
                    </div>
                    <div class="mt-2 text-xs text-zinc-500">
                        {{ stats.clicked }} clicked
                    </div>
                </template>
                <template v-else>
                    <div class="mt-1 text-xl font-semibold text-zinc-500">
                        Not tracked
                    </div>
                    <div class="mt-2 text-xs text-zinc-500">
                        No click events received yet. Enable click tracking
                        with your provider.
                    </div>
                </template>
            </div>
        </div>

        <div class="md-card mt-4 p-5">
            <div class="mb-3 text-[11px] uppercase tracking-wide text-zinc-500">
                Broadcasts
            </div>
            <p v-if="!broadcasts.length" class="text-sm text-zinc-500">
                No broadcasts sent in this window.
            </p>
            <div v-else class="overflow-x-auto">
                <table class="min-w-full text-left text-sm">
                    <thead class="border-b border-zinc-800 text-xs text-zinc-500">
                        <tr>
                            <th class="py-2 pr-4 font-medium">Broadcast</th>
                            <th class="py-2 pr-4 font-medium">Sent</th>
                            <th class="py-2 pr-4 font-medium">Delivered</th>
                            <th class="py-2 pr-4 font-medium">Bounced</th>
                            <th class="py-2 pr-4 font-medium">Complaints</th>
                            <th class="py-2 pr-4 font-medium">Opens</th>
                            <th class="py-2 pr-4 font-medium">Clicks</th>
                            <th class="py-2 pr-4 font-medium">Unsubscribed</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-900">
                        <tr v-for="b in broadcasts" :key="b.id">
                            <td class="py-2 pr-4">
                                <Link
                                    :href="route('broadcasts.show', b.id)"
                                    class="text-zinc-200 hover:text-white"
                                    >{{ b.name }}</Link
                                >
                                <div class="text-xs text-zinc-500">
                                    {{ b.sent_at }}
                                </div>
                            </td>
                            <td class="py-2 pr-4 text-zinc-300">
                                {{ b.sent }} / {{ b.recipients }}
                            </td>
                            <td class="py-2 pr-4 text-zinc-300">
                                {{ b.delivered }}
                                <span class="text-xs text-zinc-500">({{ b.delivery_rate }}%)</span>
                            </td>
                            <td class="py-2 pr-4 text-zinc-300">{{ b.bounced }}</td>
                            <td class="py-2 pr-4 text-zinc-300">{{ b.complained }}</td>
                            <td class="py-2 pr-4 text-zinc-300">
                                <template v-if="tracking.opens">
                                    {{ b.opened }}
                                    <span class="text-xs text-zinc-500">({{ b.open_rate }}%)</span>
                                </template>
                                <span v-else class="text-xs text-zinc-500">Not tracked</span>
                            </td>
                            <td class="py-2 pr-4 text-zinc-300">
                                <template v-if="tracking.clicks">
                                    {{ b.clicked }}
                                    <span class="text-xs text-zinc-500">({{ b.click_rate }}%)</span>
                                </template>
                                <span v-else class="text-xs text-zinc-500">Not tracked</span>
                            </td>
                            <td class="py-2 pr-4 text-zinc-300">{{ b.unsubscribed }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
