<script setup>
import { computed, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import { CircleHelp } from '@lucide/vue';

const props = defineProps({
    stats: {
        type: Object,
        default: () => ({
            sent: 0,
            delivered: 0,
            bounced: 0,
            deliverability: 0,
        }),
    },
    series: { type: Array, default: () => [] },
    bounceSeries: { type: Array, default: () => [] },
    complaintSeries: { type: Array, default: () => [] },
});

const hover = ref(null);

const metricPoints = computed(() => props.series);
const bounceSeries = computed(() => props.bounceSeries);

const maxY = computed(() => {
    const vals = metricPoints.value.flatMap((p) => [
        p.delivered,
        p.bounced,
        p.delayed,
    ]);
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
</script>

<template>
    <Head title="Metrics" />

    <AppLayout>
        <PageHeader
            title="Metrics"
            description="Delivery health across domains for the selected window."
        >
            <template #actions>
                <select class="md-input !w-auto !rounded-full">
                    <option>All domains</option>
                    <option>acme.com</option>
                    <option>mail.acme.com</option>
                </select>
                <select class="md-input !w-auto !rounded-full">
                    <option>Last 15 days</option>
                    <option>Last 7 days</option>
                    <option>Last 30 days</option>
                </select>
            </template>
        </PageHeader>

        <div class="md-card p-5 sm:p-6">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div class="flex flex-wrap gap-10">
                    <div>
                        <div class="text-[11px] uppercase tracking-wide text-zinc-500">
                            Emails
                        </div>
                        <div class="mt-1 text-3xl font-semibold text-white">
                            {{ stats.sent }}
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
                <select class="md-input !w-auto !rounded-full self-start">
                    <option>All events</option>
                    <option>Delivered</option>
                    <option>Bounced</option>
                    <option>Delayed</option>
                </select>
            </div>

            <div class="relative mt-6 overflow-x-auto">
                <svg
                    :viewBox="`0 0 ${chartW} ${chartH}`"
                    class="h-56 w-full min-w-[480px]"
                    @mouseleave="hover = null"
                >
                    <defs>
                        <linearGradient id="gDelivered" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stop-color="#34d399" stop-opacity="0.25" />
                            <stop offset="100%" stop-color="#34d399" stop-opacity="0" />
                        </linearGradient>
                    </defs>
                    <polyline
                        fill="none"
                        stroke="#34d399"
                        stroke-width="2"
                        :points="pointsFor('delivered')"
                    />
                    <polyline
                        fill="none"
                        stroke="#f43f5e"
                        stroke-width="2"
                        :points="pointsFor('bounced')"
                    />
                    <polyline
                        fill="none"
                        stroke="rgb(34 211 238)"
                        stroke-width="2"
                        :points="pointsFor('delayed')"
                    />
                    <g
                        v-for="(p, i) in metricPoints"
                        :key="p.day"
                        @mouseenter="hover = i"
                    >
                        <circle
                            :cx="
                                pad +
                                (i / Math.max(metricPoints.length - 1, 1)) *
                                    (chartW - pad * 2)
                            "
                            :cy="
                                chartH -
                                pad -
                                (p.delivered / maxY) * (chartH - pad * 2)
                            "
                            r="3.5"
                            class="fill-emerald-400"
                        />
                    </g>
                </svg>

                <div
                    v-if="hover !== null && metricPoints[hover]"
                    class="pointer-events-none absolute left-1/2 top-4 z-10 w-48 -translate-x-1/2 rounded-lg border border-zinc-700 bg-zinc-950 p-3 text-xs shadow-xl"
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
                            <span class="text-cyan-300">Delayed</span>
                            <span>{{ metricPoints[hover].delayed }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="mt-4 flex flex-wrap items-center gap-4 text-xs text-zinc-500">
                <span class="text-zinc-400">Last {{ metricPoints.length }} days</span>
                <span class="inline-flex items-center gap-1.5">
                    <i class="h-2 w-2 rounded-full bg-emerald-400" />
                    {{ stats.deliverability }}% delivered
                </span>
                <span class="inline-flex items-center gap-1.5">
                    <i class="h-2 w-2 rounded-full bg-rose-400" />
                    {{ stats.bounced }} bounced
                </span>
            </div>
        </div>

        <div class="mt-4 grid gap-4 md:grid-cols-2">
            <div class="md-card p-5">
                <div class="flex items-center gap-1.5 text-[11px] uppercase tracking-wide text-zinc-500">
                    Bounce rate
                    <CircleHelp :size="12" />
                </div>
                <div class="mt-1 text-3xl font-semibold text-white">
                    {{
                        stats.sent
                            ? ((stats.bounced / stats.sent) * 100).toFixed(1)
                            : '0'
                    }}%
                </div>
                <div class="mt-6 flex h-16 items-end gap-1">
                    <div
                        v-for="(v, i) in bounceSeries"
                        :key="i"
                        class="flex-1 rounded-t bg-rose-500/70"
                        :style="{ height: (v / bounceMax) * 100 + '%' }"
                    />
                </div>
            </div>
            <div class="md-card p-5">
                <div class="flex items-center gap-1.5 text-[11px] uppercase tracking-wide text-zinc-500">
                    Complain rate
                    <CircleHelp :size="12" />
                </div>
                <div class="mt-1 text-3xl font-semibold text-white">0%</div>
                <div class="mt-6 flex h-16 items-end gap-1">
                    <div
                        v-for="(v, i) in complaintSeries"
                        :key="i"
                        class="flex-1 rounded-t bg-zinc-700"
                        :style="{ height: Math.max(v * 10, 4) + '%' }"
                    />
                </div>
            </div>
        </div>
    </AppLayout>
</template>
