<script setup>
import { ref, computed, onUnmounted } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';

const props = defineProps({
    runs: { type: Array, default: () => [] },
    config: { type: Object, required: true },
});

const isRunning = ref(false);
const currentRun = ref(null);
const pollInterval = ref(null);
const includeEvents = ref(false);

const canRun = computed(() => props.config.hasResendKey && props.config.hasWebhookSecret);

const startTest = async () => {
    isRunning.value = true;
    currentRun.value = null;

    try {
        const response = await fetch(route('admin.system-test.start'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            },
            body: JSON.stringify({ include_events: includeEvents.value }),
        });

        const data = await response.json();
        currentRun.value = data.run;

        startPolling(data.run.id);
    } catch (error) {
        console.error('Failed to start test:', error);
        isRunning.value = false;
    }
};

const startPolling = (runId) => {
    pollInterval.value = setInterval(async () => {
        try {
            const response = await fetch(route('admin.system-test.poll'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: JSON.stringify({ run_id: runId }),
            });

            const data = await response.json();
            if (data.run) {
                currentRun.value = data.run;

                if (data.run.status === 'passed' || data.run.status === 'failed') {
                    stopPolling();
                    isRunning.value = false;
                    router.reload({ only: ['runs'] });
                }
            }
        } catch (error) {
            console.error('Polling error:', error);
        }
    }, 2000);
};

const stopPolling = () => {
    if (pollInterval.value) {
        clearInterval(pollInterval.value);
        pollInterval.value = null;
    }
};

onUnmounted(() => {
    stopPolling();
});

const formatDuration = (ms) => {
    if (!ms) return '-';
    if (ms < 1000) return `${Math.round(ms)}ms`;
    return `${(ms / 1000).toFixed(1)}s`;
};

const stepIcon = (success) => success ? '✓' : '✗';

const stepClass = (success) => success ? 'text-green-400' : 'text-red-400';

const statusBadgeClass = (status) => {
    switch (status) {
        case 'passed': return 'bg-green-500/20 text-green-400';
        case 'failed': return 'bg-red-500/20 text-red-400';
        case 'running': return 'bg-blue-500/20 text-blue-400';
        default: return 'bg-zinc-500/20 text-zinc-400';
    }
};
</script>

<template>
    <Head title="Admin · System Test" />

    <AdminLayout>
        <PageHeader
            title="E2E Mail Test"
            description="Test that sending and receiving email works end-to-end through Resend."
        />

        <div class="space-y-6">
            <!-- Config Status -->
            <div class="md-card p-6">
                <h3 class="mb-4 text-sm font-medium text-white">Configuration</h3>
                <div class="grid gap-4 text-sm md:grid-cols-2">
                    <div class="flex items-center gap-2">
                        <span :class="config.hasResendKey ? 'text-green-400' : 'text-red-400'">
                            {{ config.hasResendKey ? '✓' : '✗' }}
                        </span>
                        <span class="text-zinc-400">RESEND_API_KEY</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span :class="config.hasWebhookSecret ? 'text-green-400' : 'text-red-400'">
                            {{ config.hasWebhookSecret ? '✓' : '✗' }}
                        </span>
                        <span class="text-zinc-400">RESEND_WEBHOOK_SECRET</span>
                    </div>
                    <div>
                        <span class="text-zinc-500">From:</span>
                        <span class="ml-2 text-zinc-300">{{ config.from }}</span>
                    </div>
                    <div>
                        <span class="text-zinc-500">Test mailbox:</span>
                        <span class="ml-2 text-zinc-300">{{ config.mailbox }}</span>
                    </div>
                </div>
            </div>

            <!-- Run Test -->
            <div class="md-card p-6">
                <h3 class="mb-4 text-sm font-medium text-white">Run Test</h3>

                <div class="mb-4">
                    <label class="flex items-center gap-2 text-sm">
                        <input
                            v-model="includeEvents"
                            type="checkbox"
                            class="rounded border-zinc-700 bg-zinc-900"
                            :disabled="isRunning"
                        />
                        <span class="text-zinc-400">Include delivery events test</span>
                    </label>
                </div>

                <button
                    :disabled="!canRun || isRunning"
                    class="md-btn md-btn-primary"
                    @click="startTest"
                >
                    <span v-if="isRunning" class="flex items-center gap-2">
                        <svg class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                        </svg>
                        Running...
                    </span>
                    <span v-else>Run Test</span>
                </button>

                <p v-if="!canRun" class="mt-2 text-sm text-red-400">
                    Configure RESEND_API_KEY and RESEND_WEBHOOK_SECRET to run tests.
                </p>
            </div>

            <!-- Current Run Progress -->
            <div v-if="currentRun" class="md-card p-6">
                <div class="mb-4 flex items-center justify-between">
                    <h3 class="text-sm font-medium text-white">Current Test</h3>
                    <span :class="['rounded px-2 py-0.5 text-xs font-medium', statusBadgeClass(currentRun.status)]">
                        {{ currentRun.status }}
                    </span>
                </div>

                <div class="space-y-2 text-sm">
                    <div v-for="(data, step) in currentRun.steps" :key="step" class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <span :class="stepClass(data.success)">{{ stepIcon(data.success) }}</span>
                            <span class="text-zinc-300">{{ step }}</span>
                            <span v-if="data.detail" class="text-xs text-zinc-500">({{ data.detail }})</span>
                        </div>
                        <span class="text-zinc-500">{{ formatDuration(currentRun.timings?.[step]) }}</span>
                    </div>
                </div>

                <div v-if="currentRun.error" class="mt-4 rounded border border-red-500/30 bg-red-500/10 p-3">
                    <p class="text-sm text-red-400">{{ currentRun.error }}</p>
                    <p v-if="currentRun.error_hint" class="mt-1 text-xs text-zinc-400">{{ currentRun.error_hint }}</p>
                </div>

                <div v-if="currentRun.duration_ms" class="mt-4 text-sm text-zinc-500">
                    Total duration: {{ formatDuration(currentRun.duration_ms) }}
                </div>
            </div>

            <!-- Recent Runs -->
            <div class="md-card p-6">
                <h3 class="mb-4 text-sm font-medium text-white">Recent Runs</h3>

                <div v-if="runs.length === 0" class="text-sm text-zinc-500">
                    No test runs yet.
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-zinc-800 text-left text-zinc-500">
                                <th class="pb-2 pr-4">Status</th>
                                <th class="pb-2 pr-4">Source</th>
                                <th class="pb-2 pr-4">Duration</th>
                                <th class="pb-2 pr-4">Started</th>
                                <th class="pb-2">Error</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="run in runs" :key="run.id" class="border-b border-zinc-800/50">
                                <td class="py-2 pr-4">
                                    <span :class="['rounded px-2 py-0.5 text-xs font-medium', statusBadgeClass(run.status)]">
                                        {{ run.status }}
                                    </span>
                                </td>
                                <td class="py-2 pr-4 text-zinc-400">
                                    {{ run.source }}
                                    <span v-if="run.user" class="text-zinc-500">({{ run.user }})</span>
                                </td>
                                <td class="py-2 pr-4 text-zinc-400">{{ formatDuration(run.duration_ms) }}</td>
                                <td class="py-2 pr-4 text-zinc-500">{{ run.started_at || run.created_at }}</td>
                                <td class="max-w-xs truncate py-2 text-zinc-500">{{ run.error || '-' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- CLI Instructions -->
            <div class="md-card p-6">
                <h3 class="mb-4 text-sm font-medium text-white">CLI Usage</h3>
                <pre class="overflow-x-auto rounded bg-zinc-900 p-3 text-sm text-zinc-300">php artisan maildesk:e2e</pre>
                <p class="mt-2 text-xs text-zinc-500">
                    Options: <code>--events</code> (test delivery events), <code>--timeout=180</code> (seconds to wait),
                    <code>--from=address</code>, <code>--mailbox=address</code>
                </p>
            </div>
        </div>
    </AdminLayout>
</template>
