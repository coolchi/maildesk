<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';
import { useOnboarding } from '@/composables/useOnboarding';
import { useComposeModal } from '@/composables/useComposeModal';
import { Check, Circle } from '@lucide/vue';

const { state, close, complete, completedCount, total } = useOnboarding();
const { open: openCompose } = useComposeModal();

const steps = computed(() => [
    {
        key: 'domain',
        title: 'Verify a domain',
        body: 'Add SPF/DKIM so mail delivers reliably.',
        href: 'domains',
        cta: 'Open domains',
    },
    {
        key: 'apiKey',
        title: 'Create an API key',
        body: 'Authenticate your product with Bearer md_… keys.',
        href: 'api-keys',
        cta: 'Open API keys',
    },
    {
        key: 'send',
        title: 'Send your first email',
        body: 'Compose from the dashboard or hit POST /emails.',
        action: 'compose',
        cta: 'Compose',
    },
    {
        key: 'webhook',
        title: 'Add a webhook',
        body: 'Receive delivered, bounced, and inbound events.',
        href: 'webhooks',
        cta: 'Open webhooks',
    },
]);

const progress = computed(() =>
    Math.round((completedCount() / total) * 100),
);

const markAndGo = (step) => {
    complete(step.key);
    if (step.action === 'compose') {
        close();
        openCompose();
    } else {
        close();
    }
};
</script>

<template>
    <Modal
        :show="state.open"
        title="Get started with MailDesk"
        description="Four steps to ship your first production email."
        max-width="lg"
        @close="close"
    >
        <div class="mb-5">
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

        <ul class="space-y-3">
            <li
                v-for="step in steps"
                :key="step.key"
                class="flex items-start gap-3 rounded-xl border border-zinc-800 bg-black/30 p-4"
            >
                <span
                    class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full"
                    :class="
                        state.steps[step.key]
                            ? 'bg-cyan-400/20 text-cyan-300'
                            : 'bg-zinc-800 text-zinc-500'
                    "
                >
                    <Check v-if="state.steps[step.key]" :size="14" />
                    <Circle v-else :size="14" />
                </span>
                <div class="min-w-0 flex-1">
                    <div class="font-medium text-white">{{ step.title }}</div>
                    <p class="mt-1 text-sm text-zinc-400">{{ step.body }}</p>
                    <button
                        v-if="step.action"
                        type="button"
                        class="mt-3 inline-flex text-sm text-cyan-300 hover:text-cyan-200"
                        @click="markAndGo(step)"
                    >
                        {{ step.cta }} →
                    </button>
                    <Link
                        v-else
                        :href="route(step.href)"
                        class="mt-3 inline-flex text-sm text-cyan-300 hover:text-cyan-200"
                        @click="markAndGo(step)"
                    >
                        {{ step.cta }} →
                    </Link>
                </div>
            </li>
        </ul>
    </Modal>
</template>
