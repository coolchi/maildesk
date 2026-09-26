<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';
import { useOnboarding } from '@/composables/useOnboarding';
import { useComposeModal } from '@/composables/useComposeModal';
import { Check, Circle, PartyPopper } from '@lucide/vue';

const { state, steps: done, next, close, completedCount, total } = useOnboarding();
const { open: openCompose } = useComposeModal();

const steps = computed(() => [
    {
        key: 'domain',
        title: 'Verify a domain',
        body: 'Add SPF/DKIM so mail delivers reliably.',
        doneBody: 'A sending domain is verified.',
        href: 'domains',
        cta: 'Open domains',
        doneCta: 'Manage domains',
    },
    {
        key: 'apiKey',
        title: 'Create an API key',
        body: 'Authenticate your product with Bearer md_… keys.',
        doneBody: 'Your workspace has an API key.',
        href: 'api-keys',
        cta: 'Open API keys',
        doneCta: 'View keys',
    },
    {
        key: 'send',
        title: 'Send your first email',
        body: 'Compose from the dashboard or hit POST /emails.',
        doneBody: 'Your first email went out.',
        action: 'compose',
        cta: 'Compose',
        doneCta: 'Compose another',
    },
    {
        key: 'webhook',
        title: 'Add a webhook',
        body: 'Receive delivered, bounced, and inbound events.',
        doneBody: 'A webhook endpoint is set up.',
        href: 'webhooks',
        cta: 'Open webhooks',
        doneCta: 'View webhooks',
    },
]);

const progress = computed(() => Math.round((completedCount() / total) * 100));
const allDone = computed(() => completedCount() === total);

// Steps are ticked off from real workspace data, not by clicking the link.
const go = (step) => {
    close();
    if (step.action === 'compose') {
        openCompose();
    }
};
</script>

<template>
    <Modal
        :show="state.open"
        title="Get started with MailDesk"
        :description="
            allDone
                ? 'Everything is set up. You are ready to send in production.'
                : 'Four steps to ship your first production email.'
        "
        max-width="lg"
        @close="close"
    >
        <div class="mb-5" data-testid="onboarding-progress">
            <div class="mb-2 flex items-center justify-between text-xs text-zinc-500">
                <span>{{ completedCount() }} of {{ total }} complete</span>
                <span>{{ progress }}%</span>
            </div>
            <div class="h-1.5 overflow-hidden rounded-full bg-zinc-800">
                <div
                    class="h-full rounded-full bg-cyan-400 transition-all duration-500"
                    :style="{ width: progress + '%' }"
                />
            </div>
        </div>

        <div
            v-if="allDone"
            class="mb-4 flex items-center gap-3 rounded-xl border border-cyan-400/30 bg-cyan-400/10 p-4 text-sm text-cyan-100"
            data-testid="onboarding-all-done"
        >
            <PartyPopper :size="18" class="shrink-0 text-cyan-300" />
            You've finished setup. Your domain, API key, first send and webhook are all in place.
        </div>

        <ul class="space-y-3">
            <li
                v-for="step in steps"
                :key="step.key"
                :data-testid="`onboarding-step-${step.key}`"
                :data-state="done[step.key] ? 'done' : next === step.key ? 'next' : 'todo'"
                class="flex items-start gap-3 rounded-xl border p-4 transition"
                :class="
                    done[step.key]
                        ? 'border-zinc-800 bg-black/20'
                        : next === step.key
                          ? 'border-cyan-400/40 bg-cyan-400/[0.04]'
                          : 'border-zinc-800 bg-black/30'
                "
            >
                <span
                    class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full"
                    :class="done[step.key] ? 'bg-cyan-400/20 text-cyan-300' : 'bg-zinc-800 text-zinc-500'"
                >
                    <Check v-if="done[step.key]" :size="14" />
                    <Circle v-else :size="14" />
                </span>
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2">
                        <span
                            class="font-medium"
                            :class="done[step.key] ? 'text-zinc-400 line-through decoration-zinc-600' : 'text-white'"
                        >
                            {{ step.title }}
                        </span>
                        <span
                            v-if="done[step.key]"
                            class="rounded-full bg-cyan-400/15 px-2 py-0.5 text-[11px] font-medium text-cyan-300"
                        >
                            Done
                        </span>
                        <span
                            v-else-if="next === step.key"
                            class="rounded-full bg-zinc-800 px-2 py-0.5 text-[11px] font-medium text-zinc-300"
                        >
                            Up next
                        </span>
                    </div>
                    <p class="mt-1 text-sm" :class="done[step.key] ? 'text-zinc-500' : 'text-zinc-400'">
                        {{ done[step.key] ? step.doneBody : step.body }}
                    </p>
                    <button
                        v-if="step.action"
                        type="button"
                        class="mt-3 inline-flex text-sm"
                        :class="done[step.key] ? 'text-zinc-500 hover:text-zinc-300' : 'text-cyan-300 hover:text-cyan-200'"
                        @click="go(step)"
                    >
                        {{ done[step.key] ? step.doneCta : step.cta }} →
                    </button>
                    <Link
                        v-else
                        :href="route(step.href)"
                        class="mt-3 inline-flex text-sm"
                        :class="done[step.key] ? 'text-zinc-500 hover:text-zinc-300' : 'text-cyan-300 hover:text-cyan-200'"
                        @click="go(step)"
                    >
                        {{ done[step.key] ? step.doneCta : step.cta }} →
                    </Link>
                </div>
            </li>
        </ul>
    </Modal>
</template>
