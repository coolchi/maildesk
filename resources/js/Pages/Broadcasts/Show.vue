<script setup>
import { computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { useToast } from '@/composables/useToast';
import {
    ArrowLeft,
    Copy,
    Megaphone,
    Pencil,
    Users,
} from '@lucide/vue';

const props = defineProps({
    id: { type: [String, Number], required: true },
    broadcast: { type: Object, required: true },
});

const toast = useToast();

const broadcast = computed(() => props.broadcast);

const metrics = computed(() => {
    const b = broadcast.value;
    const sent = b.status === 'sent';
    return [
        {
            label: 'Recipients',
            value: Number(b.recipients || 0).toLocaleString(),
        },
        {
            label: 'Open rate',
            value: b.open_rate || '—',
        },
        {
            label: 'Click rate',
            value: sent ? '—' : '—',
        },
        {
            label: 'Unsubscribes',
            value: sent ? '—' : '—',
        },
    ];
});

const goEdit = () => router.visit(route('broadcasts.create'));

const duplicate = () => {
    router.post(
        route('broadcasts.store'),
        { source_id: props.broadcast.id, name: props.broadcast.name },
        {
            onSuccess: () => toast.success('Broadcast duplicated.'),
        },
    );
};
</script>

<template>
    <Head :title="broadcast.name" />

    <AppLayout>
        <div class="mb-6">
            <Link
                :href="route('broadcasts')"
                class="inline-flex items-center gap-1.5 text-sm text-zinc-500 hover:text-cyan-300"
            >
                <ArrowLeft :size="14" />
                Broadcasts
            </Link>
        </div>

        <div
            class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
        >
            <div class="flex items-start gap-4">
                <div
                    class="flex h-12 w-12 items-center justify-center rounded-xl border border-cyan-400/30 bg-cyan-400/10 text-cyan-300"
                >
                    <Megaphone :size="22" />
                </div>
                <div>
                    <div
                        class="text-xs uppercase tracking-wide text-zinc-500"
                    >
                        Broadcast
                    </div>
                    <h1 class="mt-1 text-2xl font-semibold text-white">
                        {{ broadcast.name }}
                    </h1>
                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        <StatusBadge :status="broadcast.status" />
                        <span class="text-sm text-zinc-500">
                            {{ broadcast.sent }}
                        </span>
                    </div>
                </div>
            </div>
            <div class="flex flex-wrap gap-2">
                <button type="button" class="md-btn-ghost" @click="duplicate">
                    <Copy :size="16" />
                    Duplicate
                </button>
                <button type="button" class="md-btn-solid" @click="goEdit">
                    <Pencil :size="16" />
                    Edit
                </button>
            </div>
        </div>

        <div class="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div
                v-for="m in metrics"
                :key="m.label"
                class="md-card p-4"
            >
                <div class="text-xs uppercase tracking-wide text-zinc-500">
                    {{ m.label }}
                </div>
                <div class="mt-1 text-2xl font-semibold tabular-nums text-white">
                    {{ m.value }}
                </div>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-[1fr_320px]">
            <section class="md-card space-y-4 p-5">
                <h2 class="text-sm font-medium text-white">Preview</h2>
                <div
                    class="overflow-hidden rounded-xl border border-zinc-800 bg-white"
                >
                    <div
                        class="border-b border-zinc-200 bg-zinc-50 px-4 py-3 text-xs text-zinc-500"
                    >
                        <div>
                            <span class="text-zinc-400">From:</span>
                            Acme &lt;hello@acme.com&gt;
                        </div>
                        <div class="mt-1">
                            <span class="text-zinc-400">Subject:</span>
                            {{ broadcast.name }}
                        </div>
                    </div>
                    <div class="space-y-3 p-6 text-sm leading-relaxed text-zinc-700">
                        <p class="text-lg font-semibold text-zinc-900">
                            {{ broadcast.name }}
                        </p>
                        <p>
                            Hi there — here’s what’s new from Acme this month.
                            We’re shipping deliverability improvements, inbox
                            tools, and a cleaner API for your product emails.
                        </p>
                        <p>
                            This is mock broadcast content so you can review the
                            reading experience before wiring a real campaign
                            editor.
                        </p>
                        <a
                            href="#"
                            class="inline-flex rounded-full bg-zinc-900 px-4 py-2 text-sm font-medium text-white"
                            @click.prevent
                        >
                            Read the update
                        </a>
                    </div>
                </div>
            </section>

            <aside class="space-y-4">
                <section class="md-card space-y-3 p-5">
                    <h2 class="text-sm font-medium text-white">Details</h2>
                    <dl class="space-y-3 text-sm">
                        <div class="flex justify-between gap-3">
                            <dt class="text-zinc-500">Audience</dt>
                            <dd
                                class="flex items-center gap-1.5 text-right text-zinc-200"
                            >
                                <Users :size="13" class="text-zinc-500" />
                                {{ broadcast.audience }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-zinc-500">Recipients</dt>
                            <dd class="tabular-nums text-zinc-200">
                                {{ broadcast.recipients.toLocaleString() }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-zinc-500">Status</dt>
                            <dd>
                                <StatusBadge :status="broadcast.status" />
                            </dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-zinc-500">Sent</dt>
                            <dd class="text-zinc-200">{{ broadcast.sent }}</dd>
                        </div>
                    </dl>
                </section>
            </aside>
        </div>
    </AppLayout>
</template>
