<script setup>
import { computed, ref, watchEffect } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import { adminPlans } from '@/data/adminMock';
import { useToast } from '@/composables/useToast';
import { Check, Plus, Trash2 } from '@lucide/vue';

const props = defineProps({
    plans: { type: Array, default: () => [] },
});

const toast = useToast();
const product = ref('transactional');
const plans = ref([]);
const saving = ref(false);

watchEffect(() => {
    const source = props.plans.length ? props.plans : adminPlans;
    plans.value = source.map((p) => ({
        ...p,
        features: (p.features || []).map((f) => ({ ...f })),
    }));
});

const filtered = computed(() =>
    plans.value.filter((p) => p.product === product.value),
);

const selectedId = ref(filtered.value[0]?.id || plans.value[0]?.id);

const selected = computed(
    () =>
        plans.value.find((p) => p.id === selectedId.value) ||
        filtered.value[0],
);

const selectProduct = (p) => {
    product.value = p;
    const first = plans.value.find((x) => x.product === p);
    if (first) selectedId.value = first.id;
};

const addFeature = () => {
    if (!selected.value) return;
    selected.value.features.push({
        id: `f_${Date.now()}`,
        label: 'New feature',
        included: true,
    });
};

const removeFeature = (id) => {
    if (!selected.value) return;
    selected.value.features = selected.value.features.filter(
        (f) => f.id !== id,
    );
};

const savePlan = () => {
    if (!selected.value?.dbId) {
        toast.error('This plan is not persisted yet.');
        return;
    }
    saving.value = true;
    router.put(
        route('admin.plans.update', selected.value.dbId),
        {
            name: selected.value.name,
            price: Number(selected.value.price) || 0,
            featured: Boolean(selected.value.featured),
            features: selected.value.features,
        },
        {
            preserveScroll: true,
            onFinish: () => {
                saving.value = false;
            },
            onSuccess: () => {
                toast.success(`${selected.value.name} plan saved.`);
            },
            onError: () => toast.error('Could not save plan.'),
        },
    );
};
</script>

<template>
    <Head title="Admin · Plans" />

    <AdminLayout>
        <PageHeader
            title="Plans & pricing"
            description="Edit catalog prices and feature lists shown to customers."
        />

        <div class="mb-5 inline-flex rounded-full border border-zinc-800 bg-zinc-950 p-1">
            <button
                v-for="p in ['transactional', 'marketing']"
                :key="p"
                type="button"
                class="rounded-full px-3 py-1.5 text-xs capitalize transition sm:text-sm"
                :class="
                    product === p
                        ? 'bg-zinc-800 font-medium text-white'
                        : 'text-zinc-500 hover:text-zinc-300'
                "
                @click="selectProduct(p)"
            >
                {{ p }}
            </button>
        </div>

        <div class="grid gap-6 lg:grid-cols-[220px_minmax(0,1fr)]">
            <aside class="space-y-1">
                <button
                    v-for="p in filtered"
                    :key="p.id"
                    type="button"
                    class="flex w-full items-center justify-between rounded-lg px-3 py-2.5 text-left text-sm transition"
                    :class="
                        selectedId === p.id
                            ? 'bg-cyan-400/10 text-cyan-300'
                            : 'text-zinc-400 hover:bg-zinc-900 hover:text-zinc-200'
                    "
                    @click="selectedId = p.id"
                >
                    <span>{{ p.name }}</span>
                    <span class="tabular-nums text-xs opacity-80"
                        >${{ p.price }}</span
                    >
                </button>
            </aside>

            <section v-if="selected" class="md-card space-y-5 p-5 sm:p-6">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-semibold text-white">
                            {{ selected.name }}
                        </h2>
                        <p class="mt-1 text-sm capitalize text-zinc-500">
                            {{ selected.product }} · billed
                            {{ selected.interval }}ly
                        </p>
                    </div>
                    <button
                        type="button"
                        class="md-btn-solid"
                        :disabled="saving"
                        @click="savePlan"
                    >
                        <Check :size="14" />
                        {{ saving ? 'Saving…' : 'Save plan' }}
                    </button>
                </div>

                <div class="grid gap-4 sm:grid-cols-3">
                    <div>
                        <label class="mb-1.5 block text-xs text-zinc-500"
                            >Monthly price (USD)</label
                        >
                        <input
                            v-model.number="selected.price"
                            type="number"
                            min="0"
                            class="md-input"
                        />
                    </div>
                    <div>
                        <label class="mb-1.5 block text-xs text-zinc-500"
                            >Plan name</label
                        >
                        <input v-model="selected.name" class="md-input" />
                    </div>
                    <div class="flex items-end">
                        <label
                            class="flex w-full items-center justify-between rounded-lg border border-zinc-800 px-3 py-2.5 text-sm text-zinc-300"
                        >
                            Featured
                            <input
                                v-model="selected.featured"
                                type="checkbox"
                                class="rounded border-zinc-700 bg-zinc-900 text-cyan-400 focus:ring-cyan-400/40"
                            />
                        </label>
                    </div>
                </div>

                <div>
                    <div class="mb-2 flex items-center justify-between gap-2">
                        <h3 class="text-sm font-medium text-white">Features</h3>
                        <button
                            type="button"
                            class="md-btn-ghost"
                            @click="addFeature"
                        >
                            <Plus :size="14" />
                            Add feature
                        </button>
                    </div>
                    <ul class="space-y-2">
                        <li
                            v-for="f in selected.features"
                            :key="f.id"
                            class="flex items-center gap-2"
                        >
                            <input
                                v-model="f.included"
                                type="checkbox"
                                class="rounded border-zinc-700 bg-zinc-900 text-cyan-400 focus:ring-cyan-400/40"
                                title="Included"
                            />
                            <input
                                v-model="f.label"
                                class="md-input"
                            />
                            <button
                                type="button"
                                class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg border border-zinc-800 text-zinc-500 transition hover:border-rose-500/40 hover:text-rose-400"
                                @click="removeFeature(f.id)"
                            >
                                <Trash2 :size="14" />
                            </button>
                        </li>
                    </ul>
                </div>
            </section>
        </div>
    </AdminLayout>
</template>
