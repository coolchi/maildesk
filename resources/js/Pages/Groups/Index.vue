<script setup>
import { computed, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import EmptyState from '@/Components/EmptyState.vue';
import Modal from '@/Components/Modal.vue';
import { useToast } from '@/composables/useToast';
import {
    Megaphone,
    Pencil,
    Plus,
    Search,
    Trash2,
    UserPlus,
    UsersRound,
    X,
} from '@lucide/vue';

const props = defineProps({
    groups: { type: Array, default: () => [] },
    domains: { type: Array, default: () => [] },
});

const toast = useToast();
const search = ref('');
const selectedId = ref(props.groups[0]?.id ?? null);
const newMembers = ref('');
const adding = ref(false);

watch(
    () => props.groups,
    (list) => {
        if (!list.find((g) => g.id === selectedId.value)) {
            selectedId.value = list[0]?.id ?? null;
        }
    },
);

const filtered = computed(() => {
    const q = search.value.trim().toLowerCase();
    if (!q) return props.groups;
    return props.groups.filter(
        (g) =>
            g.email.toLowerCase().includes(q) ||
            g.name.toLowerCase().includes(q) ||
            g.members.some((m) => m.email.includes(q)),
    );
});

const selected = computed(
    () => props.groups.find((g) => g.id === selectedId.value) || null,
);

// Create / edit modal
const showForm = ref(false);
const editing = ref(null);
const form = ref({ name: '', email: '', description: '', members: '', active: true });
const errors = ref({});
const saving = ref(false);

const openCreate = () => {
    editing.value = null;
    errors.value = {};
    form.value = {
        name: '',
        email: props.domains[0] ? `@${props.domains[0]}` : '',
        description: '',
        members: '',
        active: true,
    };
    showForm.value = true;
};

const openEdit = (group) => {
    editing.value = group;
    errors.value = {};
    form.value = {
        name: group.name,
        email: group.email,
        description: group.description || '',
        members: '',
        active: group.active,
    };
    showForm.value = true;
};

const submit = () => {
    saving.value = true;
    const opts = {
        preserveScroll: true,
        onSuccess: () => {
            showForm.value = false;
            toast.success(editing.value ? 'Group saved.' : 'Group created.');
        },
        onError: (e) => {
            errors.value = e;
        },
        onFinish: () => {
            saving.value = false;
        },
    };
    if (editing.value) {
        router.put(route('groups.update', editing.value.id), form.value, opts);
    } else {
        router.post(route('groups.store'), form.value, opts);
    }
};

const destroy = (group) => {
    if (!confirm(`Delete ${group.email}? Mail to it will stop reaching its members.`)) return;
    router.delete(route('groups.destroy', group.id), {
        preserveScroll: true,
        onSuccess: () => toast.success('Group deleted.'),
    });
};

const addMembers = () => {
    if (!selected.value || !newMembers.value.trim()) return;
    adding.value = true;
    router.post(
        route('groups.members.store', selected.value.id),
        { members: newMembers.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                newMembers.value = '';
            },
            onError: (e) => toast.error(e.members || 'Could not add members.'),
            onFinish: () => {
                adding.value = false;
            },
        },
    );
};

const removeMember = (member) => {
    router.delete(route('groups.members.destroy', [selected.value.id, member.id]), {
        preserveScroll: true,
    });
};

const broadcastTo = (group) => {
    router.visit(route('broadcasts.create', { segment: `group:${group.id}` }));
};
</script>

<template>
    <Head title="Groups" />

    <AppLayout>
        <PageHeader
            title="Groups"
            description="Shared addresses like staff@ that deliver to every member. Use them in To/Cc, or as a broadcast audience."
        >
            <template #actions>
                <button type="button" class="md-btn-solid" data-testid="group-new" @click="openCreate">
                    <Plus :size="16" />
                    New group
                </button>
            </template>
        </PageHeader>

        <EmptyState
            v-if="!groups.length"
            title="No groups yet"
            description="Create an address such as staff@yourdomain.com and add the people it should reach."
        >
            <template #icon>
                <UsersRound :size="28" :stroke-width="1.5" />
            </template>
            <template #actions>
                <button type="button" class="md-btn-solid" @click="openCreate">
                    <Plus :size="16" />
                    New group
                </button>
            </template>
        </EmptyState>

        <div v-else class="grid gap-6 lg:grid-cols-[320px_minmax(0,1fr)]">
            <div class="space-y-3">
                <div class="relative">
                    <Search
                        :size="15"
                        class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-zinc-500"
                    />
                    <input v-model="search" class="md-input pl-9" placeholder="Search groups or members…" />
                </div>
                <button
                    v-for="g in filtered"
                    :key="g.id"
                    type="button"
                    class="md-card block w-full p-4 text-left transition"
                    :class="g.id === selectedId ? '!border-cyan-400/40' : 'hover:border-zinc-700'"
                    @click="selectedId = g.id"
                >
                    <div class="flex items-center justify-between gap-2">
                        <span class="truncate font-medium text-white">{{ g.name }}</span>
                        <span
                            class="shrink-0 rounded-full px-2 py-0.5 text-[11px]"
                            :class="g.active ? 'bg-cyan-400/10 text-cyan-300' : 'bg-zinc-800 text-zinc-400'"
                        >
                            {{ g.active ? `${g.members_count} members` : 'Paused' }}
                        </span>
                    </div>
                    <div class="mt-1 truncate font-mono text-xs text-zinc-400">{{ g.email }}</div>
                </button>
            </div>

            <div v-if="selected" class="md-card p-6" data-testid="group-detail">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0">
                        <h3 class="text-lg font-medium text-white">{{ selected.name }}</h3>
                        <div class="mt-0.5 font-mono text-sm text-cyan-300">{{ selected.email }}</div>
                        <p v-if="selected.description" class="mt-2 text-sm text-zinc-400">
                            {{ selected.description }}
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <button type="button" class="md-btn-ghost" @click="broadcastTo(selected)">
                            <Megaphone :size="15" />
                            Broadcast
                        </button>
                        <button type="button" class="md-btn-ghost" @click="openEdit(selected)">
                            <Pencil :size="15" />
                            Edit
                        </button>
                        <button
                            type="button"
                            class="md-btn-ghost hover:!text-rose-400"
                            title="Delete group"
                            @click="destroy(selected)"
                        >
                            <Trash2 :size="15" />
                        </button>
                    </div>
                </div>

                <div class="mt-6 rounded-xl border border-zinc-800 p-4">
                    <label class="mb-1.5 block text-xs text-zinc-500">Add members</label>
                    <div class="flex flex-col gap-2 sm:flex-row">
                        <textarea
                            v-model="newMembers"
                            rows="1"
                            class="md-input min-h-[40px] flex-1 resize-y"
                            data-testid="group-add-members"
                            placeholder="ada@example.com, Bola <bola@example.com>"
                            @keydown.enter.exact.prevent="addMembers"
                        />
                        <button
                            type="button"
                            class="md-btn-primary shrink-0"
                            :disabled="adding || !newMembers.trim()"
                            @click="addMembers"
                        >
                            <UserPlus :size="15" />
                            Add
                        </button>
                    </div>
                    <p class="mt-1.5 text-xs text-zinc-500">
                        Separate addresses with commas or new lines. Other groups can't be members.
                    </p>
                </div>

                <div class="mt-5">
                    <div class="mb-2 text-xs uppercase tracking-wide text-zinc-500">
                        Members ({{ selected.members.length }})
                    </div>
                    <p v-if="!selected.members.length" class="py-6 text-center text-sm text-zinc-500">
                        No members yet. Mail to this address won't reach anyone until you add some.
                    </p>
                    <ul v-else class="divide-y divide-zinc-800/70">
                        <li
                            v-for="m in selected.members"
                            :key="m.id"
                            class="group flex items-center justify-between gap-3 py-2.5"
                        >
                            <div class="min-w-0">
                                <div class="truncate text-sm text-zinc-200">{{ m.name || m.email }}</div>
                                <div v-if="m.name" class="truncate font-mono text-xs text-zinc-500">{{ m.email }}</div>
                            </div>
                            <button
                                type="button"
                                class="rounded-md p-1.5 text-zinc-500 transition hover:bg-zinc-800 hover:text-rose-400"
                                title="Remove member"
                                @click="removeMember(m)"
                            >
                                <X :size="14" />
                            </button>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <Modal
            :show="showForm"
            max-width="lg"
            :title="editing ? 'Edit group' : 'New group address'"
            description="Mail sent to this address is copied to every member."
            @close="showForm = false"
        >
            <form class="space-y-4" @submit.prevent="submit">
                <div>
                    <label class="mb-1.5 block text-xs text-zinc-500">Name</label>
                    <input v-model="form.name" class="md-input" placeholder="Staff" data-testid="group-name" />
                    <p v-if="errors.name" class="mt-1 text-xs text-rose-400">{{ errors.name }}</p>
                </div>
                <div>
                    <label class="mb-1.5 block text-xs text-zinc-500">Address</label>
                    <input v-model="form.email" class="md-input font-mono" placeholder="staff@yourdomain.com" data-testid="group-email" />
                    <p v-if="errors.email" class="mt-1 text-xs text-rose-400">{{ errors.email }}</p>
                    <p v-else-if="domains.length" class="mt-1 text-xs text-zinc-500">
                        Must be on {{ domains.join(', ') }}.
                    </p>
                </div>
                <div>
                    <label class="mb-1.5 block text-xs text-zinc-500">Description (optional)</label>
                    <input v-model="form.description" class="md-input" placeholder="Everyone on the team" />
                </div>
                <div v-if="!editing">
                    <label class="mb-1.5 block text-xs text-zinc-500">Members</label>
                    <textarea
                        v-model="form.members"
                        rows="3"
                        class="md-input resize-y"
                        placeholder="ada@example.com, bola@example.com"
                    />
                    <p v-if="errors.members" class="mt-1 text-xs text-rose-400">{{ errors.members }}</p>
                </div>
                <label v-else class="flex items-center gap-2 text-sm text-zinc-200">
                    <input
                        v-model="form.active"
                        type="checkbox"
                        class="rounded border-zinc-700 bg-zinc-900 text-cyan-400 focus:ring-cyan-400/40"
                    />
                    Active (uncheck to pause delivery)
                </label>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" class="md-btn-ghost" @click="showForm = false">Cancel</button>
                    <button type="submit" class="md-btn-primary" :disabled="saving" data-testid="group-save">
                        {{ saving ? 'Saving…' : editing ? 'Save' : 'Create group' }}
                    </button>
                </div>
            </form>
        </Modal>
    </AppLayout>
</template>
