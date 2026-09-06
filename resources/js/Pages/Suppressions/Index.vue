<script setup>
import { computed, ref, watch } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import EmptyState from '@/Components/EmptyState.vue';
import Modal from '@/Components/Modal.vue';
import { useToast } from '@/composables/useToast';
import { Ban, Plus, Search, Trash2 } from '@lucide/vue';
import { router } from '@inertiajs/vue3';

const props = defineProps({
    suppressions: { type: Array, default: () => [] },
});

const toast = useToast();
const search = ref('');
const showAdd = ref(false);
const email = ref('');
const reason = ref('Manual suppression');

const rows = ref([]);
watch(
    () => props.suppressions,
    (list) => {
        rows.value = (list || []).map((r) => ({ ...r }));
    },
    { immediate: true },
);

const filtered = computed(() => {
    const q = search.value.trim().toLowerCase();
    if (!q) return rows.value;
    return rows.value.filter(
        (r) =>
            r.email.toLowerCase().includes(q) ||
            (r.reason || '').toLowerCase().includes(q),
    );
});

const add = () => {
    const e = email.value.trim().toLowerCase();
    if (!e.includes('@')) {
        toast.error('Enter a valid email.');
        return;
    }
    router.post(
        route('suppressions.store'),
        { email: e, reason: reason.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                email.value = '';
                showAdd.value = false;
                toast.success('Address suppressed.');
            },
            onError: () => toast.error('Could not suppress address.'),
        },
    );
};

const remove = (row) => {
    router.delete(route('suppressions.destroy', row.id), {
        preserveScroll: true,
        onSuccess: () => toast.success('Removed from suppressions.'),
    });
};
</script>

<template>
    <Head title="Suppressions" />

    <AppLayout>
        <PageHeader
            title="Suppressions"
            description="Addresses excluded from sending to protect deliverability."
        >
            <template #actions>
                <button
                    type="button"
                    class="md-btn-solid"
                    @click="showAdd = true"
                >
                    <Ban :size="16" />
                    Add email
                </button>
            </template>
        </PageHeader>

        <div class="mb-4 max-w-sm">
            <div class="relative">
                <Search
                    :size="15"
                    class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-zinc-500"
                />
                <input
                    v-model="search"
                    class="md-input pl-9"
                    placeholder="Search suppressions…"
                />
            </div>
        </div>

        <EmptyState
            v-if="!filtered.length"
            title="No suppressions"
            description="Hard bounces and complaints appear here automatically."
        >
            <template #icon>
                <Ban :size="28" :stroke-width="1.5" />
            </template>
            <template #actions>
                <button type="button" class="md-btn-solid" @click="showAdd = true">
                    <Plus :size="16" />
                    Add email
                </button>
            </template>
        </EmptyState>

        <div v-else class="md-table-wrap">
            <table class="min-w-full text-left text-sm">
                <thead
                    class="border-b border-zinc-800 text-xs uppercase tracking-wide text-zinc-500"
                >
                    <tr>
                        <th class="px-4 py-3 font-medium">Email</th>
                        <th class="px-4 py-3 font-medium">Reason</th>
                        <th class="px-4 py-3 font-medium">Added</th>
                        <th class="px-4 py-3 font-medium" />
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-900">
                    <tr
                        v-for="row in filtered"
                        :key="row.email"
                        class="group"
                    >
                        <td class="px-4 py-3 text-zinc-200">{{ row.email }}</td>
                        <td class="px-4 py-3 text-zinc-400">{{ row.reason }}</td>
                        <td class="px-4 py-3 text-zinc-500">{{ row.created }}</td>
                        <td class="px-4 py-3 text-right">
                            <button
                                type="button"
                                class="rounded-md p-1.5 text-zinc-600 opacity-0 transition hover:bg-zinc-800 hover:text-rose-400 group-hover:opacity-100"
                                title="Remove"
                                @click="remove(row)"
                            >
                                <Trash2 :size="14" />
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Modal
            :show="showAdd"
            title="Add suppression"
            description="MailDesk will skip sending to this address."
            @close="showAdd = false"
        >
            <div class="space-y-3">
                <input
                    v-model="email"
                    class="md-input"
                    placeholder="email@example.com"
                    @keyup.enter="add"
                />
                <select v-model="reason" class="md-input">
                    <option>Manual suppression</option>
                    <option>Hard bounce</option>
                    <option>Complaint</option>
                    <option>Unsubscribe</option>
                </select>
            </div>
            <template #footer>
                <button
                    type="button"
                    class="md-btn-ghost"
                    @click="showAdd = false"
                >
                    Cancel
                </button>
                <button type="button" class="md-btn-primary" @click="add">
                    Suppress
                </button>
            </template>
        </Modal>
    </AppLayout>
</template>
