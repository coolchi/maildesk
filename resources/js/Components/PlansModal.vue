 <script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { mockPlans } from '@/data/mock';
import { usePlansModal } from '@/composables/usePlansModal';
import { Check, X } from '@lucide/vue';

const { state, close } = usePlansModal();
const page = usePage();

const mode = ref('marketing');
const volumeIndex = ref(1);

watch(
    () => state.open,
    (open) => {
        if (open) {
            mode.value = state.mode || 'marketing';
            volumeIndex.value = 1;
            document.body.style.overflow = 'hidden';
        } else {
            document.body.style.overflow = '';
        }
    },
);

watch(mode, () => {
    volumeIndex.value = 1;
});

const onKey = (e) => {
    if (e.key === 'Escape' && state.open) close();
};

onMounted(() => window.addEventListener('keydown', onKey));
onUnmounted(() => {
    window.removeEventListener('keydown', onKey);
    document.body.style.overflow = '';
});

const sharedPlans = computed(() => page.props.plans || null);
const config = computed(
    () => sharedPlans.value?.[mode.value] || mockPlans[mode.value],
);
const volumeLabel = computed(() => config.value.labels[volumeIndex.value]);

const freePrice = computed(() => config.value.prices.free[volumeIndex.value]);
const proPrice = computed(() => config.value.prices.pro[volumeIndex.value]);

const formatPrice = (n) => {
    if (n === null || n === undefined) return null;
    return n === 0 ? '$0' : `$${n}`;
};

const marketingFeatures = {
    free: [
        { label: 'Ticket support', ok: true },
        { label: '10,000 automation runs', ok: true },
        { label: '3 segments', ok: true },
        { label: '3 domains', ok: true },
        { label: '5 AI credits', ok: true },
        { label: 'Marketing analytics', ok: false },
        { label: 'Dedicated IPs', ok: false },
        { label: 'Single Sign-On', ok: false },
    ],
    pro: [
        { label: 'Slack + ticket support', ok: true },
        { label: 'Unlimited automation runs', ok: true },
        { label: 'Unlimited segments', ok: true },
        { label: 'Unlimited domains', ok: true },
        { label: '100 AI credits', ok: true },
        { label: 'Marketing analytics', ok: true },
        { label: 'Dedicated IPs (add-on)', ok: false },
        { label: 'Single Sign-On', ok: false },
    ],
    custom: [
        { label: 'Priority support', ok: true },
        { label: 'Unlimited automation runs', ok: true },
        { label: 'Unlimited segments', ok: true },
        { label: 'Unlimited domains', ok: true },
        { label: 'Custom AI credits', ok: true },
        { label: 'Marketing analytics', ok: true },
        { label: 'Dedicated IPs', ok: true },
        { label: 'Single Sign-On', ok: true },
    ],
};

const transactionalFeatures = {
    free: [
        { label: '100 emails / day', ok: true },
        { label: '1 domain', ok: true },
        { label: 'API + SMTP', ok: true },
        { label: 'Ticket support', ok: true },
        { label: 'Data retention 3 days', ok: true },
        { label: 'Dedicated IPs', ok: false },
        { label: 'SSO', ok: false },
        { label: 'SLA', ok: false },
    ],
    pro: [
        { label: 'Flexible volume', ok: true },
        { label: 'Unlimited domains', ok: true },
        { label: 'API + SMTP', ok: true },
        { label: 'Slack support', ok: true },
        { label: 'Data retention 30 days', ok: true },
        { label: 'Dedicated IPs (add-on)', ok: false },
        { label: 'SSO', ok: false },
        { label: 'SLA', ok: false },
    ],
    custom: [
        { label: 'Custom volume', ok: true },
        { label: 'Unlimited domains', ok: true },
        { label: 'API + SMTP', ok: true },
        { label: 'Priority support', ok: true },
        { label: 'Custom retention', ok: true },
        { label: 'Dedicated IPs', ok: true },
        { label: 'SSO', ok: true },
        { label: 'Custom SLA', ok: true },
    ],
};

const features = computed(() =>
    mode.value === 'marketing' ? marketingFeatures : transactionalFeatures,
);

const metricLabel = computed(() =>
    mode.value === 'marketing'
        ? `${volumeLabel.value} contacts`
        : `${volumeLabel.value} emails / mo`,
);
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition duration-150 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="state.open"
                class="fixed inset-0 z-[90] flex items-end justify-center overflow-y-auto bg-black/80 p-4 sm:items-center sm:p-6"
                @click.self="close"
            >
                <Transition
                    enter-active-class="transition duration-200 ease-out"
                    enter-from-class="translate-y-4 opacity-0 sm:scale-95"
                    enter-to-class="translate-y-0 opacity-100 sm:scale-100"
                    leave-active-class="transition duration-150 ease-in"
                    leave-from-class="opacity-100 sm:scale-100"
                    leave-to-class="translate-y-4 opacity-0 sm:scale-95"
                    appear
                >
                    <div
                        v-if="state.open"
                        class="relative my-4 w-full max-w-5xl rounded-2xl border border-zinc-800 bg-zinc-950 shadow-2xl"
                        role="dialog"
                        aria-modal="true"
                        aria-labelledby="plans-title"
                    >
                        <button
                            type="button"
                            class="absolute right-4 top-4 z-10 rounded-lg p-1.5 text-zinc-500 hover:bg-zinc-900 hover:text-white"
                            @click="close"
                        >
                            <X :size="18" />
                        </button>

                        <div class="max-h-[90vh] overflow-y-auto px-5 py-8 sm:px-8">
                            <div class="mb-8 text-center">
                                <h2
                                    id="plans-title"
                                    class="text-2xl font-semibold tracking-tight text-white"
                                >
                                    Plans
                                </h2>
                                <p class="mt-2 text-sm text-zinc-400">
                                    Choose transactional or marketing volume for
                                    your workspace.
                                </p>
                            </div>

                            <div class="mb-8 flex justify-center">
                                <div
                                    class="inline-flex rounded-full border border-zinc-800 bg-black p-1"
                                >
                                    <button
                                        type="button"
                                        class="rounded-full px-4 py-2 text-sm transition"
                                        :class="
                                            mode === 'transactional'
                                                ? 'bg-zinc-800 text-white'
                                                : 'text-zinc-500 hover:text-zinc-300'
                                        "
                                        @click="mode = 'transactional'"
                                    >
                                        Transactional emails
                                    </button>
                                    <button
                                        type="button"
                                        class="rounded-full px-4 py-2 text-sm transition"
                                        :class="
                                            mode === 'marketing'
                                                ? 'bg-zinc-800 text-white'
                                                : 'text-zinc-500 hover:text-zinc-300'
                                        "
                                        @click="mode = 'marketing'"
                                    >
                                        Marketing emails
                                    </button>
                                </div>
                            </div>

                            <div class="mb-8 px-1">
                                <div
                                    class="mb-3 flex items-center justify-between text-sm"
                                >
                                    <span class="text-zinc-500">Volume</span>
                                    <span class="font-medium text-cyan-300">{{
                                        metricLabel
                                    }}</span>
                                </div>
                                <input
                                    v-model.number="volumeIndex"
                                    type="range"
                                    min="0"
                                    :max="config.labels.length - 1"
                                    step="1"
                                    class="h-1.5 w-full cursor-pointer appearance-none rounded-full bg-zinc-800 accent-cyan-400"
                                />
                                <div
                                    class="mt-2 flex justify-between text-[11px] text-zinc-600"
                                >
                                    <span
                                        v-for="(label, i) in config.labels"
                                        :key="label"
                                        :class="{
                                            'text-zinc-300': i === volumeIndex,
                                        }"
                                    >
                                        {{ label }}
                                    </span>
                                </div>
                            </div>

                            <div class="grid gap-4 pb-2 lg:grid-cols-3">
                                <div
                                    class="flex flex-col rounded-2xl border border-zinc-800 bg-black/40 p-5"
                                >
                                    <div class="text-center text-sm text-zinc-400">
                                        Free
                                    </div>
                                    <div
                                        class="mt-3 text-center text-3xl font-semibold text-white"
                                    >
                                        {{ formatPrice(freePrice) }}
                                        <span
                                            class="text-base font-normal text-zinc-500"
                                            >/ mo</span
                                        >
                                    </div>
                                    <div
                                        class="mt-2 text-center text-sm text-zinc-400"
                                    >
                                        {{
                                            mode === 'marketing'
                                                ? '1,000 contacts'
                                                : '3,000 emails / mo'
                                        }}
                                    </div>
                                    <div
                                        class="mt-1 text-center text-xs text-zinc-600"
                                    >
                                        {{
                                            mode === 'marketing'
                                                ? 'Unlimited broadcast sending'
                                                : 'Community support'
                                        }}
                                    </div>
                                    <div class="my-5 border-t border-zinc-800" />
                                    <ul class="flex-1 space-y-2.5 text-sm">
                                        <li
                                            v-for="f in features.free"
                                            :key="f.label"
                                            class="flex items-center gap-2"
                                            :class="
                                                f.ok
                                                    ? 'text-zinc-300'
                                                    : 'text-zinc-600'
                                            "
                                        >
                                            <span
                                                class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full"
                                                :class="
                                                    f.ok
                                                        ? 'bg-cyan-400/20 text-cyan-300'
                                                        : 'bg-zinc-800 text-zinc-600'
                                                "
                                            >
                                                <Check v-if="f.ok" :size="12" />
                                                <X v-else :size="12" />
                                            </span>
                                            {{ f.label }}
                                        </li>
                                    </ul>
                                    <Link
                                        :href="route('settings', 'billing')"
                                        class="md-btn-ghost mt-6 w-full text-center"
                                        @click="close"
                                    >
                                        Current plan
                                    </Link>
                                </div>

                                <div
                                    class="relative flex flex-col rounded-2xl border border-cyan-400/40 bg-black/40 p-5 shadow-[0_0_40px_-12px_rgba(34,211,238,0.35)]"
                                >
                                    <div
                                        class="absolute -top-3 left-1/2 -translate-x-1/2 rounded-full bg-cyan-400 px-2.5 py-0.5 text-[11px] font-medium text-zinc-950"
                                    >
                                        Recommended
                                    </div>
                                    <div class="text-center text-sm text-zinc-400">
                                        {{
                                            mode === 'marketing'
                                                ? 'Pro marketing'
                                                : 'Pro'
                                        }}
                                    </div>
                                    <div
                                        class="mt-3 text-center text-3xl font-semibold text-white"
                                    >
                                        <template v-if="proPrice !== null">
                                            {{ formatPrice(proPrice) }}
                                            <span
                                                class="text-base font-normal text-zinc-500"
                                                >/ mo</span
                                            >
                                        </template>
                                        <template v-else>Custom</template>
                                    </div>
                                    <div
                                        class="mt-2 text-center text-sm text-zinc-400"
                                    >
                                        {{ metricLabel }}
                                    </div>
                                    <div
                                        class="mt-1 text-center text-xs text-zinc-600"
                                    >
                                        {{
                                            mode === 'marketing'
                                                ? 'Unlimited broadcast sending'
                                                : 'Production deliverability'
                                        }}
                                    </div>
                                    <div class="my-5 border-t border-zinc-800" />
                                    <ul class="flex-1 space-y-2.5 text-sm">
                                        <li
                                            v-for="f in features.pro"
                                            :key="f.label"
                                            class="flex items-center gap-2"
                                            :class="
                                                f.ok
                                                    ? 'text-zinc-300'
                                                    : 'text-zinc-600'
                                            "
                                        >
                                            <span
                                                class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full"
                                                :class="
                                                    f.ok
                                                        ? 'bg-cyan-400/20 text-cyan-300'
                                                        : 'bg-zinc-800 text-zinc-600'
                                                "
                                            >
                                                <Check v-if="f.ok" :size="12" />
                                                <X v-else :size="12" />
                                            </span>
                                            {{ f.label }}
                                        </li>
                                    </ul>
                                    <Link
                                        :href="route('settings', 'billing')"
                                        class="md-btn-primary mt-6 w-full text-center"
                                        @click="close"
                                    >
                                        {{
                                            proPrice === null
                                                ? 'Contact sales'
                                                : 'Upgrade'
                                        }}
                                    </Link>
                                </div>

                                <div
                                    class="flex flex-col rounded-2xl border border-zinc-800 bg-black/40 p-5"
                                >
                                    <div class="text-center text-sm text-zinc-400">
                                        Custom
                                    </div>
                                    <div
                                        class="mt-3 text-center text-3xl font-semibold text-white"
                                    >
                                        Enterprise
                                    </div>
                                    <div
                                        class="mt-2 text-center text-sm text-zinc-400"
                                    >
                                        Performance at any scale
                                    </div>
                                    <div
                                        class="mt-1 text-center text-xs text-zinc-600"
                                    >
                                        Dedicated success + security
                                    </div>
                                    <div class="my-5 border-t border-zinc-800" />
                                    <ul class="flex-1 space-y-2.5 text-sm">
                                        <li
                                            v-for="f in features.custom"
                                            :key="f.label"
                                            class="flex items-center gap-2 text-zinc-300"
                                        >
                                            <span
                                                class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-cyan-400/20 text-cyan-300"
                                            >
                                                <Check :size="12" />
                                            </span>
                                            {{ f.label }}
                                        </li>
                                    </ul>
                                    <Link
                                        :href="route('settings', 'billing')"
                                        class="md-btn-ghost mt-6 w-full text-center"
                                        @click="close"
                                    >
                                        Contact sales
                                    </Link>
                                </div>
                            </div>
                        </div>
                    </div>
                </Transition>
            </div>
        </Transition>
    </Teleport>
</template>
