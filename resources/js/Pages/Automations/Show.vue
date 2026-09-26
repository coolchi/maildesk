<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { useToast } from '@/composables/useToast';
import {
    ArrowLeft,
    Clock,
    Mail,
    Plus,
    Save,
    Trash2,
    Zap,
} from '@lucide/vue';

const props = defineProps({
    id: { type: [String, Number], default: null },
    automation: { type: Object, default: null },
});

const toast = useToast();
const existing = computed(() => props.automation);

const name = ref(existing.value?.name || 'Untitled Automation');
const status = ref(existing.value?.status || 'disabled');
const triggerOptions = [
    { id: 'user.created', name: 'user.created' },
    { id: 'email.opened', name: 'email.opened' },
    { id: 'contact.added', name: 'contact.added' },
];
const trigger = ref(existing.value?.trigger || triggerOptions[0].name);
const steps = ref(
    existing.value?.steps?.length
        ? existing.value.steps.map((s) => ({ ...s }))
        : [
              { type: 'trigger', label: `When ${trigger.value}` },
              { type: 'email', label: 'Send welcome email' },
          ],
);

const delayOptions = ['Wait 5 minutes', 'Wait 1 hour', 'Wait 1 day', 'Wait 2 days'];
const emailOptions = ['Send welcome email', 'Send follow-up'];

const syncTriggerLabel = () => {
    if (steps.value[0]?.type === 'trigger') {
        steps.value[0].label = `When ${trigger.value}`;
    }
};

const addDelay = () => {
    steps.value.push({ type: 'delay', label: delayOptions[1] });
};

const addEmail = () => {
    steps.value.push({
        type: 'email',
        label: emailOptions[0] || 'Send email',
    });
};

const removeStep = (index) => {
    if (index === 0) return;
    steps.value.splice(index, 1);
};

const save = () => {
    syncTriggerLabel();
    const payload = {
        name: name.value,
        status: status.value,
        trigger: trigger.value,
        steps: steps.value.map((step) => ({
            type: step.type,
            label: step.label,
            ...(step.config ? { config: step.config } : {}),
        })),
    };
    const options = {
        preserveScroll: true,
        onSuccess: () => toast.success('Automation saved.'),
    };
    if (!props.id) {
        router.post(route('automations.store'), payload, options);
        return;
    }
    router.put(route('automations.update', props.id), payload, options);
};

const stepIcon = (type) => {
    if (type === 'trigger') return Zap;
    if (type === 'delay') return Clock;
    return Mail;
};
</script>

<template>
    <Head :title="name" />

    <AppLayout>
        <div class="mb-4">
            <Link
                :href="route('automations')"
                class="inline-flex items-center gap-1.5 text-sm text-zinc-500 hover:text-cyan-300"
            >
                <ArrowLeft :size="14" />
                Automations
            </Link>
        </div>

        <PageHeader :title="name" description="Build a trigger → action workflow.">
            <template #actions>
                <StatusBadge :status="status" />
                <button
                    type="button"
                    class="md-btn-ghost"
                    @click="
                        status = status === 'enabled' ? 'disabled' : 'enabled'
                    "
                >
                    {{ status === 'enabled' ? 'Disable' : 'Enable' }}
                </button>
                <button type="button" class="md-btn-primary" @click="save">
                    <Save :size="16" />
                    Save
                </button>
            </template>
        </PageHeader>

        <div class="grid gap-6 lg:grid-cols-[280px_1fr]">
            <aside class="md-card space-y-4 p-5 h-fit">
                <div>
                    <label class="mb-1.5 block text-xs text-zinc-500">Name</label>
                    <input v-model="name" class="md-input" />
                </div>
                <div>
                    <label class="mb-1.5 block text-xs text-zinc-500"
                        >Trigger event</label
                    >
                    <select
                        v-model="trigger"
                        class="md-input font-mono text-xs"
                        @change="syncTriggerLabel"
                    >
                        <option
                            v-for="event in triggerOptions"
                            :key="event.id"
                            :value="event.name"
                        >
                            {{ event.name }}
                        </option>
                    </select>
                </div>
                <div class="rounded-lg border border-zinc-800 bg-black/40 p-3 text-xs text-zinc-500">
                    Fire this event from your API to start the workflow.
                    <code class="mt-2 block text-cyan-300">POST /events</code>
                </div>
            </aside>

            <div class="space-y-3">
                <div
                    v-for="(step, index) in steps"
                    :key="index"
                    class="md-card relative flex items-start gap-4 p-4"
                >
                    <span
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-zinc-800 text-cyan-300"
                    >
                        <component :is="stepIcon(step.type)" :size="18" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <div class="text-[11px] uppercase tracking-wide text-zinc-500">
                            {{ step.type }}
                        </div>
                        <select
                            v-if="step.type === 'delay'"
                            v-model="step.label"
                            class="md-input mt-2"
                        >
                            <option v-for="opt in delayOptions" :key="opt">
                                {{ opt }}
                            </option>
                        </select>
                        <select
                            v-else-if="step.type === 'email'"
                            v-model="step.label"
                            class="md-input mt-2"
                        >
                            <option v-for="opt in emailOptions" :key="opt">
                                {{ opt }}
                            </option>
                        </select>
                        <div v-else class="mt-1 font-medium text-white">
                            {{ step.label }}
                        </div>
                    </div>
                    <button
                        v-if="index > 0"
                        type="button"
                        class="rounded-md p-1.5 text-zinc-500 hover:bg-zinc-900 hover:text-rose-400"
                        @click="removeStep(index)"
                    >
                        <Trash2 :size="16" />
                    </button>
                    <div
                        v-if="index < steps.length - 1"
                        class="absolute -bottom-3 left-[2.15rem] h-3 w-px bg-zinc-800"
                    />
                </div>

                <div class="flex flex-wrap gap-2 pt-2">
                    <button type="button" class="md-btn-ghost" @click="addDelay">
                        <Plus :size="14" />
                        Add delay
                    </button>
                    <button type="button" class="md-btn-ghost" @click="addEmail">
                        <Plus :size="14" />
                        Add email
                    </button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
