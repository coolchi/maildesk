<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import WysiwygEditor from '@/Components/WysiwygEditor.vue';
import { useToast } from '@/composables/useToast';
import { useAiFeatures } from '@/composables/useAiFeatures';
import { ArrowLeft, Send, Sparkles } from '@lucide/vue';

const props = defineProps({
    segments: { type: Array, default: () => [] },
    selected: { type: String, default: null },
});

const toast = useToast();
const { broadcastAssist } = useAiFeatures();
const step = ref(1);
const assisting = ref(false);
const subjectSuggestions = ref([]);

const form = ref({
    name: '',
    segment:
        props.selected && props.segments.some((s) => s.id === props.selected)
            ? props.selected
            : 'all',
    subject: '',
    from: 'Acme <hello@acme.com>',
    html: '<h2>What\'s new</h2><p>Share your announcement here…</p>',
    schedule: 'now',
    brief: '',
});

const segments = computed(() =>
    props.segments.length
        ? props.segments
        : [
              { id: 'all', label: 'All subscribed contacts', count: 0 },
          ],
);

const canNext = computed(() => {
    if (step.value === 1) return form.value.name.trim() && form.value.segment;
    if (step.value === 2)
        return form.value.subject.trim() && form.value.html.trim();
    return true;
});

const next = () => {
    if (!canNext.value) {
        toast.error('Fill in the required fields.');
        return;
    }
    step.value += 1;
};

const runBroadcastAssist = async () => {
    if (!broadcastAssist.value || assisting.value) {
        return;
    }

    assisting.value = true;
    try {
        const { data } = await window.axios.post(route('ai.broadcast'), {
            brief: form.value.brief || form.value.name,
            subject: form.value.subject,
            html: form.value.html,
        });
        subjectSuggestions.value = data.subjects || [];
        if (data.subjects?.[0]) {
            form.value.subject = data.subjects[0];
        }
        if (data.html) {
            form.value.html = data.html;
        }
        toast.success('Campaign copy updated.');
    } catch (error) {
        toast.error(
            error?.response?.data?.message ||
                'Broadcast assist failed.',
        );
    } finally {
        assisting.value = false;
    }
};

const send = () => {
    router.post(
        route('broadcasts.store'),
        {
            name: form.value.name,
            subject: form.value.subject,
            html: form.value.html,
            segment: form.value.segment,
            from: form.value.from,
            send_now: form.value.schedule === 'now',
        },
        {
            onSuccess: () =>
                toast.success(
                    form.value.schedule === 'now'
                        ? 'Broadcast queued for sending.'
                        : 'Draft saved.',
                ),
            onError: () => toast.error('Could not create broadcast.'),
        },
    );
};
</script>

<template>
    <Head title="New broadcast" />

    <AppLayout>
        <div class="mb-4">
            <Link
                :href="route('broadcasts')"
                class="inline-flex items-center gap-1.5 text-sm text-zinc-500 hover:text-cyan-300"
            >
                <ArrowLeft :size="14" />
                Broadcasts
            </Link>
        </div>

        <PageHeader
            title="New broadcast"
            description="Audience → content → send"
        />

        <div class="mb-6 flex flex-wrap gap-2">
            <span
                v-for="s in [
                    { n: 1, label: 'Audience' },
                    { n: 2, label: 'Content' },
                    { n: 3, label: 'Review' },
                ]"
                :key="s.n"
                class="rounded-full px-3 py-1.5 text-xs"
                :class="
                    step === s.n
                        ? 'bg-cyan-400/15 text-cyan-300'
                        : step > s.n
                          ? 'bg-emerald-500/10 text-emerald-300'
                          : 'bg-zinc-900 text-zinc-500'
                "
            >
                {{ s.n }}. {{ s.label }}
            </span>
        </div>

        <!-- Step 1 -->
        <div v-if="step === 1" class="md-card max-w-2xl space-y-4 p-6">
            <div>
                <label class="mb-1.5 block text-xs text-zinc-500"
                    >Broadcast name</label
                >
                <input
                    v-model="form.name"
                    class="md-input"
                    placeholder="March product update"
                />
            </div>
            <div>
                <div class="mb-2 text-xs text-zinc-500">Audience segment</div>
                <div class="space-y-2">
                    <button
                        v-for="seg in segments"
                        :key="seg.id"
                        type="button"
                        class="flex w-full items-center justify-between rounded-xl border px-4 py-3 text-left transition"
                        :class="
                            form.segment === seg.id
                                ? 'border-cyan-400/50 bg-cyan-400/10'
                                : 'border-zinc-800 hover:border-zinc-600'
                        "
                        @click="form.segment = seg.id"
                    >
                        <span>
                            <span class="block text-sm text-white">{{
                                seg.label
                            }}</span>
                            <span class="text-xs text-zinc-500"
                                >{{ seg.count }}
                                {{ String(seg.id).startsWith('group:') ? 'members' : 'contacts' }}</span
                            >
                        </span>
                        <span
                            class="h-4 w-4 rounded-full border"
                            :class="
                                form.segment === seg.id
                                    ? 'border-cyan-400 bg-cyan-400'
                                    : 'border-zinc-600'
                            "
                        />
                    </button>
                </div>
            </div>
            <div class="flex justify-end pt-2">
                <button type="button" class="md-btn-primary" @click="next">
                    Continue
                </button>
            </div>
        </div>

        <!-- Step 2 -->
        <div v-else-if="step === 2" class="md-card max-w-3xl space-y-4 p-6">
            <div
                v-if="broadcastAssist"
                class="rounded-lg border border-zinc-800 bg-zinc-950/40 p-3"
            >
                <div class="mb-2 flex items-center justify-between gap-2">
                    <label class="text-xs text-zinc-500">AI brief (optional)</label>
                    <button
                        type="button"
                        class="inline-flex items-center gap-1 text-xs text-cyan-300 hover:text-cyan-200 disabled:opacity-50"
                        data-testid="broadcast-ai-assist"
                        :disabled="assisting"
                        @click="runBroadcastAssist"
                    >
                        <Sparkles :size="12" />
                        {{ assisting ? 'Writing…' : 'Improve with AI' }}
                    </button>
                </div>
                <input
                    v-model="form.brief"
                    class="md-input"
                    placeholder="Announce our spring sale with 20% off"
                />
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="mb-1.5 block text-xs text-zinc-500">From</label>
                    <input v-model="form.from" class="md-input" />
                </div>
                <div>
                    <label class="mb-1.5 block text-xs text-zinc-500"
                        >Subject</label
                    >
                    <input
                        v-model="form.subject"
                        class="md-input"
                        placeholder="What's new this month"
                    />
                    <div
                        v-if="subjectSuggestions.length"
                        class="mt-2 flex flex-wrap gap-1.5"
                    >
                        <button
                            v-for="suggestion in subjectSuggestions"
                            :key="suggestion"
                            type="button"
                            class="rounded-full border border-zinc-700 px-2.5 py-1 text-[11px] text-zinc-300 hover:border-cyan-400/40 hover:text-cyan-200"
                            @click="form.subject = suggestion"
                        >
                            {{ suggestion }}
                        </button>
                    </div>
                </div>
            </div>
            <div>
                <label class="mb-1.5 block text-xs text-zinc-500">Body</label>
                <WysiwygEditor v-model="form.html" min-height="280px" />
            </div>
            <div class="flex justify-between pt-2">
                <button type="button" class="md-btn-ghost" @click="step = 1">
                    Back
                </button>
                <button type="button" class="md-btn-primary" @click="next">
                    Review
                </button>
            </div>
        </div>

        <!-- Step 3 -->
        <div v-else class="grid max-w-4xl gap-4 lg:grid-cols-2">
            <div class="md-card space-y-4 p-6">
                <h3 class="font-medium text-white">Summary</h3>
                <dl class="space-y-3 text-sm">
                    <div>
                        <dt class="text-zinc-500">Name</dt>
                        <dd class="text-zinc-200">{{ form.name }}</dd>
                    </div>
                    <div>
                        <dt class="text-zinc-500">Segment</dt>
                        <dd class="text-zinc-200">
                            {{
                                segments.find((s) => s.id === form.segment)
                                    ?.label
                            }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-zinc-500">Subject</dt>
                        <dd class="text-zinc-200">{{ form.subject }}</dd>
                    </div>
                </dl>
                <div>
                    <div class="mb-2 text-xs text-zinc-500">When</div>
                    <div class="flex gap-2">
                        <button
                            type="button"
                            class="rounded-full px-3 py-1.5 text-xs"
                            :class="
                                form.schedule === 'now'
                                    ? 'bg-cyan-400/15 text-cyan-300'
                                    : 'bg-zinc-900 text-zinc-400'
                            "
                            @click="form.schedule = 'now'"
                        >
                            Send now
                        </button>
                        <button
                            type="button"
                            class="rounded-full px-3 py-1.5 text-xs"
                            :class="
                                form.schedule === 'later'
                                    ? 'bg-cyan-400/15 text-cyan-300'
                                    : 'bg-zinc-900 text-zinc-400'
                            "
                            @click="form.schedule = 'later'"
                        >
                            Schedule
                        </button>
                    </div>
                </div>
                <div class="flex gap-2 pt-2">
                    <button type="button" class="md-btn-ghost" @click="step = 2">
                        Back
                    </button>
                    <button type="button" class="md-btn-primary" @click="send">
                        <Send :size="16" />
                        {{
                            form.schedule === 'now' ? 'Send broadcast' : 'Schedule'
                        }}
                    </button>
                </div>
            </div>
            <div>
                <div class="mb-2 text-xs text-zinc-500">Preview</div>
                <div
                    class="overflow-hidden rounded-xl border border-zinc-800 bg-white p-6 text-zinc-900 shadow"
                    v-html="form.html"
                />
            </div>
        </div>
    </AppLayout>
</template>
