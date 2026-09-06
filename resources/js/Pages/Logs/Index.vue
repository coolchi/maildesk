<script setup>
import { computed, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import EmptyState from '@/Components/EmptyState.vue';
import { ScrollText, Search } from '@lucide/vue';

const props = defineProps({
    logs: { type: Array, default: () => [] },
});

const search = ref('');
const level = ref('all');

const levelClass = {
    info: 'text-cyan-300',
    warn: 'text-amber-300',
    error: 'text-rose-400',
};

const filtered = computed(() => {
    const q = search.value.trim().toLowerCase();
    return props.logs.filter((log) => {
        const levelOk = level.value === 'all' || log.level === level.value;
        const searchOk =
            !q ||
            log.event.toLowerCase().includes(q) ||
            log.message.toLowerCase().includes(q);
        return levelOk && searchOk;
    });
});
</script>

<template>
    <Head title="Logs" />

    <AppLayout>
        <PageHeader
            title="Logs"
            description="Recent provider and webhook activity for this workspace."
        />

        <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center">
            <div class="relative min-w-0 flex-1">
                <Search
                    :size="15"
                    class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-zinc-500"
                />
                <input
                    v-model="search"
                    class="md-input pl-9"
                    placeholder="Search events or messages…"
                />
            </div>
            <div class="inline-flex rounded-full border border-zinc-800 bg-zinc-950 p-1">
                <button
                    v-for="opt in ['all', 'info', 'warn', 'error']"
                    :key="opt"
                    type="button"
                    class="rounded-full px-3 py-1.5 text-xs capitalize transition sm:text-sm"
                    :class="
                        level === opt
                            ? 'bg-zinc-800 text-white'
                            : 'text-zinc-500 hover:text-zinc-300'
                    "
                    @click="level = opt"
                >
                    {{ opt }}
                </button>
            </div>
        </div>

        <EmptyState
            v-if="!filtered.length"
            title="No log entries"
            description="Try another filter or wait for new activity."
        >
            <template #icon>
                <ScrollText :size="28" :stroke-width="1.5" />
            </template>
        </EmptyState>

        <div v-else class="md-card divide-y divide-zinc-800">
            <div
                v-for="log in filtered"
                :key="log.id"
                class="flex flex-col gap-1 px-4 py-3 transition hover:bg-zinc-900/40 sm:flex-row sm:items-center sm:justify-between"
            >
                <div class="min-w-0">
                    <div class="flex items-center gap-2 text-xs">
                        <span
                            class="font-mono uppercase"
                            :class="levelClass[log.level]"
                            >{{ log.level }}</span
                        >
                        <span class="font-mono text-zinc-500">{{
                            log.event
                        }}</span>
                    </div>
                    <div class="mt-1 truncate text-sm text-zinc-200">
                        {{ log.message }}
                    </div>
                </div>
                <div class="shrink-0 text-xs text-zinc-500">{{ log.time }}</div>
            </div>
        </div>
    </AppLayout>
</template>
