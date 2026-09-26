<script setup>
import { computed, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import EmptyState from '@/Components/EmptyState.vue';
import Modal from '@/Components/Modal.vue';
import { useToast } from '@/composables/useToast';
import { AlertTriangle, Check, Package, Plus, Trash2 } from '@lucide/vue';
import { formatNaira, isMonipayPayable } from '@/utils/planPricing';

const props = defineProps({
    plans: { type: Array, default: () => [] },
    monipayMinKobo: { type: Number, default: 5000 },
});

const toast = useToast();
const product = ref('transactional');
const plans = ref([]);
const saving = ref(false);
const selectedId = ref(null);

const clonePlan = (p) => ({
    ...p,
    price_ngn: p.price_ngn ?? '',
    features: (p.features || []).map((f) => ({ ...f })),
});

watch(
    () => props.plans,
    (list) => {
        plans.value = list.map(clonePlan);
        if (!plans.value.some((p) => p.id === selectedId.value)) {
            selectedId.value =
                plans.value.find((p) => p.product === product.value)?.id ||
                plans.value[0]?.id ||
                null;
        }
    },
    { immediate: true },
);

const filtered = computed(() => plans.value.filter((p) => p.product === product.value));

const selected = computed(
    () => plans.value.find((p) => p.id === selectedId.value) || filtered.value[0] || null,
);

const minNaira = computed(() => props.monipayMinKobo / 100);

const payable = (plan) => isMonipayPayable(plan, props.monipayMinKobo);

const selectProduct = (p) => {
    product.value = p;
    selectedId.value = plans.value.find((x) => x.product === p)?.id || null;
};

const addFeature = () => {
    selected.value?.features.push({ id: `f_${Date.now()}`, label: 'New feature', included: true });
};

const removeFeature = (id) => {
    if (!selected.value) return;
    selected.value.features = selected.value.features.filter((f) => f.id !== id);
};

const nullableInt = (v) => (v === '' || v === null || v === undefined ? null : Number(v));
const nullableNaira = (v) => (v === '' || v === null || v === undefined ? null : Number(v));

const firstError = (errors, fallback) => Object.values(errors || {})[0] || fallback;

const savePlan = () => {
    const plan = selected.value;
    if (!plan?.dbId) return;
    saving.value = true;
    router.put(
        route('admin.plans.update', plan.dbId),
        {
            name: plan.name,
            price: Number(plan.price) || 0,
            price_ngn: nullableNaira(plan.price_ngn),
            interval: plan.interval || 'month',
            emails: nullableInt(plan.emails),
            contacts: nullableInt(plan.contacts),
            seats: nullableInt(plan.seats),
            featured: Boolean(plan.featured),
            features: plan.features,
        },
        {
            preserveScroll: true,
            onFinish: () => (saving.value = false),
            onSuccess: () => toast.success(`${plan.name} plan saved.`),
            onError: (errors) => toast.error(firstError(errors, 'Could not save plan.')),
        },
    );
};

// ── Create ────────────────────────────────────────────────────────────
const showCreate = ref(false);
const createErrors = ref({});
const blankCreate = () => ({
    key: '',
    product: product.value,
    name: '',
    price: 0,
    price_ngn: '',
    interval: 'month',
    emails: '',
    contacts: '',
    seats: '',
    featured: false,
});
const createForm = ref(blankCreate());

const openCreate = () => {
    createForm.value = blankCreate();
    createErrors.value = {};
    showCreate.value = true;
};

const createPlan = () => {
    const f = createForm.value;
    saving.value = true;
    router.post(
        route('admin.plans.store'),
        {
            key: f.key.trim(),
            product: f.product,
            name: f.name.trim(),
            price: Number(f.price) || 0,
            price_ngn: nullableNaira(f.price_ngn),
            interval: f.interval,
            emails: nullableInt(f.emails),
            contacts: nullableInt(f.contacts),
            seats: nullableInt(f.seats),
            featured: Boolean(f.featured),
        },
        {
            preserveScroll: true,
            onFinish: () => (saving.value = false),
            onSuccess: () => {
                showCreate.value = false;
                product.value = f.product;
                selectedId.value = f.key.trim();
                toast.success(`Plan ${f.name} created.`);
            },
            onError: (errors) => {
                createErrors.value = errors;
                toast.error(firstError(errors, 'Could not create plan.'));
            },
        },
    );
};

// ── Delete ────────────────────────────────────────────────────────────
const deleteTarget = ref(null);

const confirmDelete = () => {
    const plan = deleteTarget.value;
    if (!plan) return;
    router.delete(route('admin.plans.destroy', plan.dbId), {
        preserveScroll: true,
        onSuccess: (page) => {
            deleteTarget.value = null;
            if (!page.props.errors?.plan) toast.success(`Plan ${plan.name} deleted.`);
        },
        onError: (errors) => {
            deleteTarget.value = null;
            toast.error(firstError(errors, 'Could not delete plan.'));
        },
    });
};
</script>

<template>
    <Head title="Admin · Plans" />

    <AdminLayout>
        <PageHeader
            title="Plans & pricing"
            description="Edit catalog prices (USD and ₦ NGN), limits and feature lists shown to customers."
        >
            <template #actions>
                <button type="button" class="md-btn-solid" @click="openCreate">
                    <Plus :size="14" />
                    New plan
                </button>
            </template>
        </PageHeader>

        <EmptyState
            v-if="!plans.length"
            title="No plans yet"
            description="Create your first plan to start selling subscriptions."
        >
            <template #icon>
                <Package :size="28" :stroke-width="1.5" />
            </template>
            <template #actions>
                <button type="button" class="md-btn-solid" @click="openCreate">
                    <Plus :size="14" />
                    New plan
                </button>
            </template>
        </EmptyState>

        <template v-else>
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

            <div class="grid gap-6 lg:grid-cols-[260px_minmax(0,1fr)]">
                <aside class="space-y-1">
                    <p v-if="!filtered.length" class="px-3 py-2 text-sm text-zinc-500">
                        No {{ product }} plans.
                    </p>
                    <button
                        v-for="p in filtered"
                        :key="p.id"
                        type="button"
                        class="flex w-full items-start justify-between gap-2 rounded-lg px-3 py-2.5 text-left text-sm transition"
                        :class="
                            selectedId === p.id
                                ? 'bg-cyan-400/10 text-cyan-300'
                                : 'text-zinc-400 hover:bg-zinc-900 hover:text-zinc-200'
                        "
                        @click="selectedId = p.id"
                    >
                        <span class="min-w-0">
                            <span class="block truncate">{{ p.name }}</span>
                            <span
                                v-if="!payable(p)"
                                class="mt-0.5 inline-flex items-center gap-1 text-[10px] text-amber-300"
                                data-testid="not-payable"
                            >
                                <AlertTriangle :size="10" />
                                Not payable via Monipay
                            </span>
                        </span>
                        <span class="shrink-0 text-right text-xs tabular-nums opacity-80">
                            <span class="block">${{ p.price }}/{{ p.interval === 'year' ? 'yr' : 'mo' }}</span>
                            <span class="block">{{ formatNaira(p.price_kobo) }}</span>
                        </span>
                    </button>
                </aside>

                <section v-if="selected" class="md-card space-y-5 p-5 sm:p-6">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h2 class="text-lg font-semibold text-white">{{ selected.name }}</h2>
                            <p class="mt-1 text-sm capitalize text-zinc-500">
                                {{ selected.product }} · billed {{ selected.interval }}ly ·
                                {{ selected.subscriptions_count }} subscription(s)
                            </p>
                        </div>
                        <div class="flex gap-2">
                            <button
                                type="button"
                                class="md-btn-ghost text-rose-300"
                                :disabled="selected.subscriptions_count > 0"
                                :title="
                                    selected.subscriptions_count > 0
                                        ? 'In use by subscriptions — move them first'
                                        : 'Delete plan'
                                "
                                @click="deleteTarget = selected"
                            >
                                <Trash2 :size="14" />
                                Delete
                            </button>
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
                    </div>

                    <div
                        v-if="!payable(selected)"
                        class="rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-xs text-amber-200"
                    >
                        Not payable via Monipay: paid plans need a naira price of at least
                        ₦{{ minNaira.toLocaleString() }}.
                    </div>

                    <div class="grid gap-4 sm:grid-cols-3">
                        <div>
                            <label class="mb-1.5 block text-xs text-zinc-500">Price (USD)</label>
                            <input v-model.number="selected.price" type="number" min="0" class="md-input" />
                        </div>
                        <div>
                            <label class="mb-1.5 block text-xs text-zinc-500">Price (₦ NGN)</label>
                            <input
                                v-model="selected.price_ngn"
                                type="number"
                                min="0"
                                step="0.01"
                                class="md-input"
                                placeholder="not set"
                            />
                        </div>
                        <div>
                            <label class="mb-1.5 block text-xs text-zinc-500">Interval</label>
                            <select v-model="selected.interval" class="md-input">
                                <option value="month">Monthly</option>
                                <option value="year">Yearly</option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1.5 block text-xs text-zinc-500">Plan name</label>
                            <input v-model="selected.name" class="md-input" />
                        </div>
                        <div class="flex items-end sm:col-span-2">
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
                        <div>
                            <label class="mb-1.5 block text-xs text-zinc-500">Emails / month</label>
                            <input v-model="selected.emails" type="number" min="0" class="md-input" placeholder="unlimited" />
                        </div>
                        <div>
                            <label class="mb-1.5 block text-xs text-zinc-500">Contacts</label>
                            <input v-model="selected.contacts" type="number" min="0" class="md-input" placeholder="unlimited" />
                        </div>
                        <div>
                            <label class="mb-1.5 block text-xs text-zinc-500">Seats</label>
                            <input v-model="selected.seats" type="number" min="0" class="md-input" placeholder="unlimited" />
                        </div>
                    </div>

                    <div>
                        <div class="mb-2 flex items-center justify-between gap-2">
                            <h3 class="text-sm font-medium text-white">Features</h3>
                            <button type="button" class="md-btn-ghost" @click="addFeature">
                                <Plus :size="14" />
                                Add feature
                            </button>
                        </div>
                        <ul class="space-y-2">
                            <li v-for="f in selected.features" :key="f.id" class="flex items-center gap-2">
                                <input
                                    v-model="f.included"
                                    type="checkbox"
                                    class="rounded border-zinc-700 bg-zinc-900 text-cyan-400 focus:ring-cyan-400/40"
                                    title="Included"
                                />
                                <input v-model="f.label" class="md-input" />
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
        </template>

        <Modal :show="showCreate" title="New plan" max-width="lg" @close="showCreate = false">
            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <label class="mb-1.5 block text-xs text-zinc-500">Key (unique, e.g. tx_growth)</label>
                    <input v-model="createForm.key" class="md-input" />
                    <p v-if="createErrors.key" class="mt-1 text-xs text-rose-300">{{ createErrors.key }}</p>
                </div>
                <div>
                    <label class="mb-1.5 block text-xs text-zinc-500">Name</label>
                    <input v-model="createForm.name" class="md-input" />
                    <p v-if="createErrors.name" class="mt-1 text-xs text-rose-300">{{ createErrors.name }}</p>
                </div>
                <div>
                    <label class="mb-1.5 block text-xs text-zinc-500">Product</label>
                    <select v-model="createForm.product" class="md-input capitalize">
                        <option value="transactional">Transactional</option>
                        <option value="marketing">Marketing</option>
                    </select>
                </div>
                <div>
                    <label class="mb-1.5 block text-xs text-zinc-500">Interval</label>
                    <select v-model="createForm.interval" class="md-input">
                        <option value="month">Monthly</option>
                        <option value="year">Yearly</option>
                    </select>
                </div>
                <div>
                    <label class="mb-1.5 block text-xs text-zinc-500">Price (USD)</label>
                    <input v-model.number="createForm.price" type="number" min="0" class="md-input" />
                    <p v-if="createErrors.price" class="mt-1 text-xs text-rose-300">{{ createErrors.price }}</p>
                </div>
                <div>
                    <label class="mb-1.5 block text-xs text-zinc-500">Price (₦ NGN)</label>
                    <input v-model="createForm.price_ngn" type="number" min="0" step="0.01" class="md-input" placeholder="optional" />
                    <p v-if="createErrors.price_ngn" class="mt-1 text-xs text-rose-300">{{ createErrors.price_ngn }}</p>
                    <p v-else-if="Number(createForm.price) > 0 && !createForm.price_ngn" class="mt-1 text-[11px] text-amber-300">
                        Without a ₦ price this plan is not payable via Monipay.
                    </p>
                </div>
                <div>
                    <label class="mb-1.5 block text-xs text-zinc-500">Emails / month</label>
                    <input v-model="createForm.emails" type="number" min="0" class="md-input" placeholder="unlimited" />
                </div>
                <div>
                    <label class="mb-1.5 block text-xs text-zinc-500">Contacts</label>
                    <input v-model="createForm.contacts" type="number" min="0" class="md-input" placeholder="unlimited" />
                </div>
                <div>
                    <label class="mb-1.5 block text-xs text-zinc-500">Seats</label>
                    <input v-model="createForm.seats" type="number" min="0" class="md-input" placeholder="unlimited" />
                </div>
                <label class="flex items-center justify-between rounded-lg border border-zinc-800 px-3 py-2.5 text-sm text-zinc-300">
                    Featured
                    <input v-model="createForm.featured" type="checkbox" class="rounded border-zinc-700 bg-zinc-900 text-cyan-400" />
                </label>
            </div>
            <template #footer>
                <button type="button" class="md-btn-ghost" @click="showCreate = false">Cancel</button>
                <button type="button" class="md-btn-solid" :disabled="saving" @click="createPlan">
                    <Plus :size="14" />
                    {{ saving ? 'Creating…' : 'Create plan' }}
                </button>
            </template>
        </Modal>

        <Modal
            :show="!!deleteTarget"
            title="Delete plan?"
            :description="deleteTarget ? `Delete ${deleteTarget.name}? This cannot be undone.` : ''"
            @close="deleteTarget = null"
        >
            <template #footer>
                <button type="button" class="md-btn-ghost" @click="deleteTarget = null">Cancel</button>
                <button type="button" class="md-btn-solid !bg-rose-500 hover:!bg-rose-400" @click="confirmDelete">
                    <Trash2 :size="14" />
                    Delete plan
                </button>
            </template>
        </Modal>
    </AdminLayout>
</template>
