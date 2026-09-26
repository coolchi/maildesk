<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import EmptyState from '@/Components/EmptyState.vue';
import Modal from '@/Components/Modal.vue';
import RowActions from '@/Components/RowActions.vue';
import { useToast } from '@/composables/useToast';
import {
    Check,
    Inbox,
    LogIn,
    Mail,
    Megaphone,
    Pencil,
    Plus,
    Power,
    Search,
    Send,
    Trash2,
    UserCog,
    X,
} from '@lucide/vue';

const props = defineProps({
    users: { type: Array, default: () => [] },
    domainOptions: { type: Array, default: () => [] },
    canImpersonateTeam: { type: Boolean, default: false },
});

const page = usePage();
const toast = useToast();
const search = ref('');
const roleFilter = ref('all');
const statusFilter = ref('all');
const users = ref(props.users.map((u) => ({ ...u })));

watch(
    () => props.users,
    (value) => {
        users.value = value.map((u) => ({ ...u }));
    },
);

const showForm = ref(false);
const showDelete = ref(false);
const editing = ref(null);
const deleteTarget = ref(null);

const showImpersonate = ref(false);
const impersonating = ref(false);
const impersonateErrors = ref({});
const impersonateForm = ref({ userId: null, reason: '' });
const impersonateTarget = ref(null);

const reasonLength = computed(() => impersonateForm.value.reason.trim().length);
const canStartImpersonation = computed(
    () =>
        !!impersonateForm.value.userId &&
        reasonLength.value >= 10 &&
        reasonLength.value <= 500 &&
        !impersonating.value,
);

const domains = computed(() =>
    props.domainOptions.length
        ? props.domainOptions
        : ['example.com'],
);

const blankForm = () => ({
    name: '',
    local: '',
    domain: domains.value[0] || 'example.com',
    role: 'staff',
    inbox: true,
    transactional: false,
    marketing: false,
    limit: 1000,
    password: '',
    password_confirmation: '',
});

const form = ref(blankForm());

const filtered = computed(() => {
    const q = search.value.trim().toLowerCase();
    return users.value.filter((u) => {
        const roleOk = roleFilter.value === 'all' || u.role === roleFilter.value;
        const statusOk =
            statusFilter.value === 'all' || u.status === statusFilter.value;
        const searchOk =
            !q ||
            u.name.toLowerCase().includes(q) ||
            u.email.toLowerCase().includes(q);
        return roleOk && statusOk && searchOk;
    });
});

const stats = computed(() => ({
    total: users.value.length,
    active: users.value.filter((u) => u.status === 'active').length,
    pending: users.value.filter((u) => u.status === 'pending').length,
    inactive: users.value.filter((u) => u.status === 'inactive').length,
}));

const openCreate = () => {
    editing.value = null;
    form.value = blankForm();
    showForm.value = true;
};

const openEdit = (user) => {
    editing.value = user;
    const [local, domain] = user.email.split('@');
    form.value = {
        name: user.name,
        local: local || '',
        domain: domain || domains.value[0],
        role: user.role,
        inbox: user.inbox,
        transactional: user.transactional,
        marketing: user.marketing,
        limit: user.usage?.limit ?? 1000,
        password: '',
        password_confirmation: '',
    };
    showForm.value = true;
};

const openImpersonate = (user) => {
    if (!user?.can_impersonate || !user.user_id) return;
    impersonateTarget.value = user;
    impersonateForm.value = { userId: user.user_id, reason: '' };
    impersonateErrors.value = {};
    showImpersonate.value = true;
};

const startImpersonation = () => {
    if (!canStartImpersonation.value) return;
    router.post(
        route('team.impersonate', impersonateForm.value.userId),
        { reason: impersonateForm.value.reason.trim() },
        {
            preserveScroll: true,
            onStart: () => {
                impersonating.value = true;
            },
            onFinish: () => {
                impersonating.value = false;
            },
            onError: (errors) => {
                impersonateErrors.value = errors;
                toast.error(
                    errors.user ||
                        errors.reason ||
                        'Could not start session.',
                );
            },
        },
    );
};

onMounted(() => {
    const error = page.props.flash?.error;
    if (error && /impersonat|log in as/i.test(error)) toast.error(error);
});

const saveUser = () => {
    const local = form.value.local.trim().toLowerCase();
    const name = form.value.name.trim();
    if (!name || !local) {
        toast.error('Name and email local-part are required.');
        return;
    }
    if (!form.value.inbox && !form.value.transactional && !form.value.marketing) {
        toast.error('Assign at least inbox, transactional, or marketing.');
        return;
    }
    if (!editing.value && !form.value.password) {
        toast.error('Password is required.');
        return;
    }
    if (
        form.value.password &&
        form.value.password !== form.value.password_confirmation
    ) {
        toast.error('Password confirmation does not match.');
        return;
    }

    if (editing.value) {
        const payload = {
            name,
            role: form.value.role,
            inbox: form.value.inbox,
            transactional: form.value.transactional,
            marketing: form.value.marketing,
            limit: Number(form.value.limit) || 0,
        };
        if (form.value.password) {
            payload.password = form.value.password;
            payload.password_confirmation = form.value.password_confirmation;
        }
        router.put(route('users.update', editing.value.id), payload, {
            preserveScroll: true,
            onSuccess: () => {
                showForm.value = false;
                toast.success('User updated.');
            },
            onError: (errors) =>
                toast.error(
                    errors.local ||
                        errors.password ||
                        errors.name ||
                        'Could not update.',
                ),
        });
        return;
    }

    router.post(
        route('users.store'),
        {
            name,
            local,
            domain: form.value.domain,
            role: form.value.role,
            inbox: form.value.inbox,
            transactional: form.value.transactional,
            marketing: form.value.marketing,
            limit: Number(form.value.limit) || 0,
            password: form.value.password,
            password_confirmation: form.value.password_confirmation,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                showForm.value = false;
                toast.success(`Created ${local}@${form.value.domain}`);
            },
            onError: (errors) =>
                toast.error(
                    errors.local ||
                        errors.domain ||
                        errors.password ||
                        'Could not create.',
                ),
        },
    );
};

const toggleStatus = (user) => {
    if (user.status === 'pending') return;
    router.put(
        route('users.update', user.id),
        {
            status: user.status === 'active' ? 'inactive' : 'active',
        },
        {
            preserveScroll: true,
            onSuccess: () =>
                toast.success(
                    user.status === 'active'
                        ? `${user.email} deactivated.`
                        : `${user.email} activated.`,
                ),
        },
    );
};

const approveUser = (user) => {
    router.post(
        route('users.approve', user.id),
        {},
        {
            preserveScroll: true,
            onSuccess: () => toast.success(`${user.email} approved.`),
            onError: () => toast.error('Could not approve user.'),
        },
    );
};

const rejectUser = (user) => {
    router.post(
        route('users.reject', user.id),
        {},
        {
            preserveScroll: true,
            onSuccess: () => toast.info('Registration rejected.'),
            onError: () => toast.error('Could not reject registration.'),
        },
    );
};

const askDelete = (user) => {
    deleteTarget.value = user;
    showDelete.value = true;
};

const confirmDelete = () => {
    if (!deleteTarget.value) return;
    router.delete(route('users.destroy', deleteTarget.value.id), {
        onSuccess: () => {
            showDelete.value = false;
            deleteTarget.value = null;
            toast.info('User deleted.');
        },
    });
};

const actionsFor = (user) => {
    if (user.status === 'pending') {
        return [
            { id: 'approve', label: 'Approve', icon: Check },
            { id: 'reject', label: 'Reject', icon: X, danger: true },
        ];
    }

    const items = [
        { id: 'edit', label: 'Edit', icon: Pencil },
        {
            id: 'toggle',
            label: user.status === 'active' ? 'Deactivate' : 'Activate',
            icon: Power,
        },
    ];
    if (user.can_impersonate) {
        items.push({ id: 'impersonate', label: 'Log in as', icon: LogIn });
    }
    items.push({ id: 'delete', label: 'Delete', icon: Trash2, danger: true });
    return items;
};

const onAction = (user, item) => {
    if (item.id === 'edit') openEdit(user);
    else if (item.id === 'toggle') toggleStatus(user);
    else if (item.id === 'approve') approveUser(user);
    else if (item.id === 'reject') rejectUser(user);
    else if (item.id === 'impersonate') openImpersonate(user);
    else if (item.id === 'delete') askDelete(user);
};

const usagePct = (u) => {
    if (!u.usage?.limit) return 0;
    return Math.min(100, Math.round((u.usage.sent / u.usage.limit) * 100));
};

const roleHint = {
    admin: 'Mailbox role label',
    developer: 'API & transactional focus',
    staff: 'Inbox & assigned products',
};
</script>

<template>
    <Head title="Users" />

    <AppLayout>
        <PageHeader
            title="Users"
            description="Sign-in accounts with a mailbox address. Product access is set with Inbox, Transactional, and Marketing. Pending registrations appear here for approval."
        >
            <template #actions>
                <button type="button" class="md-btn-solid" @click="openCreate">
                    <Plus :size="16" />
                    Add user
                </button>
            </template>
        </PageHeader>

        <div class="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="md-card px-4 py-3">
                <div class="text-xs uppercase tracking-wide text-zinc-500">
                    Users
                </div>
                <div class="mt-1 text-2xl font-semibold text-white">
                    {{ stats.total }}
                </div>
            </div>
            <div class="md-card px-4 py-3">
                <div class="text-xs uppercase tracking-wide text-zinc-500">
                    Active
                </div>
                <div class="mt-1 text-2xl font-semibold text-emerald-400">
                    {{ stats.active }}
                </div>
            </div>
            <div class="md-card px-4 py-3">
                <div class="text-xs uppercase tracking-wide text-zinc-500">
                    Pending
                </div>
                <div class="mt-1 text-2xl font-semibold text-amber-300">
                    {{ stats.pending }}
                </div>
            </div>
            <div class="md-card px-4 py-3">
                <div class="text-xs uppercase tracking-wide text-zinc-500">
                    Inactive
                </div>
                <div class="mt-1 text-2xl font-semibold text-zinc-400">
                    {{ stats.inactive }}
                </div>
            </div>
        </div>

        <div class="mb-4 flex flex-col gap-3 lg:flex-row lg:items-center">
            <div class="relative min-w-0 flex-1">
                <Search
                    :size="15"
                    class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-zinc-500"
                />
                <input
                    v-model="search"
                    class="md-input pl-9"
                    placeholder="Search name or email…"
                />
            </div>
            <select v-model="roleFilter" class="md-input w-full lg:w-44">
                <option value="all">All roles</option>
                <option value="admin">Admin</option>
                <option value="developer">Developer</option>
                <option value="staff">Staff</option>
            </select>
            <select v-model="statusFilter" class="md-input w-full lg:w-40">
                <option value="all">All statuses</option>
                <option value="active">Active</option>
                <option value="pending">Pending</option>
                <option value="inactive">Inactive</option>
            </select>
        </div>

        <EmptyState
            v-if="!filtered.length"
            title="No users found"
            description="Create a custom address like hello@deskky.com and assign access."
        >
            <template #icon>
                <UserCog :size="28" :stroke-width="1.5" />
            </template>
            <template #actions>
                <button type="button" class="md-btn-solid" @click="openCreate">
                    <Plus :size="16" />
                    Add user
                </button>
            </template>
        </EmptyState>

        <div v-else class="md-table-wrap overflow-x-auto">
            <table class="min-w-full text-left text-sm">
                <thead
                    class="border-b border-zinc-800 text-xs uppercase tracking-wide text-zinc-500"
                >
                    <tr>
                        <th class="px-4 py-3 font-medium">User</th>
                        <th class="px-4 py-3 font-medium">Role</th>
                        <th class="px-4 py-3 font-medium">Access</th>
                        <th class="px-4 py-3 font-medium">Usage</th>
                        <th class="px-4 py-3 font-medium">Status</th>
                        <th class="whitespace-nowrap px-4 py-3 font-medium">
                            Last active
                        </th>
                        <th class="w-12 px-4 py-3" />
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-900">
                    <tr
                        v-for="user in filtered"
                        :key="user.id"
                        class="hover:bg-white/[0.03]"
                        :class="{
                            'opacity-60': user.status === 'inactive',
                            'bg-amber-400/[0.03]': user.status === 'pending',
                        }"
                    >
                        <td class="px-4 py-3.5">
                            <div class="flex min-w-[12rem] items-center gap-3">
                                <span
                                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-cyan-400/15 text-xs font-semibold text-cyan-300"
                                >
                                    {{ user.name.slice(0, 1).toUpperCase() }}
                                </span>
                                <div class="min-w-0">
                                    <div class="font-medium text-white">
                                        {{ user.name }}
                                    </div>
                                    <div
                                        class="truncate font-mono text-xs text-zinc-500"
                                    >
                                        {{ user.email }}
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-3.5">
                            <StatusBadge :status="user.role" />
                        </td>
                        <td class="px-4 py-3.5">
                            <div class="flex flex-wrap gap-1">
                                <span
                                    v-if="user.inbox"
                                    class="inline-flex items-center gap-1 rounded-md bg-zinc-800/80 px-1.5 py-0.5 text-[11px] text-zinc-300"
                                    title="Inbox"
                                >
                                    <Inbox :size="11" />
                                    Inbox
                                </span>
                                <span
                                    v-if="user.transactional"
                                    class="inline-flex items-center gap-1 rounded-md bg-cyan-400/10 px-1.5 py-0.5 text-[11px] text-cyan-300"
                                    title="Transactional"
                                >
                                    <Send :size="11" />
                                    Txn
                                </span>
                                <span
                                    v-if="user.marketing"
                                    class="inline-flex items-center gap-1 rounded-md bg-violet-400/10 px-1.5 py-0.5 text-[11px] text-violet-300"
                                    title="Marketing"
                                >
                                    <Megaphone :size="11" />
                                    Mkt
                                </span>
                            </div>
                        </td>
                        <td class="px-4 py-3.5">
                            <div class="min-w-[120px]">
                                <div
                                    class="mb-1 flex justify-between text-[11px] text-zinc-500"
                                >
                                    <span
                                        >{{
                                            user.usage.sent.toLocaleString()
                                        }}
                                        sent</span
                                    >
                                    <span
                                        >{{
                                            user.usage.limit.toLocaleString()
                                        }}</span
                                    >
                                </div>
                                <div
                                    class="h-1.5 overflow-hidden rounded-full bg-zinc-800"
                                >
                                    <div
                                        class="h-full rounded-full bg-cyan-400"
                                        :style="{
                                            width: usagePct(user) + '%',
                                        }"
                                    />
                                </div>
                                <div class="mt-1 text-[11px] text-zinc-600">
                                    {{ user.usage.inbox }} inbox msgs
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-3.5">
                            <StatusBadge :status="user.status" />
                        </td>
                        <td
                            class="whitespace-nowrap px-4 py-3.5 text-zinc-500"
                        >
                            {{ user.last_active }}
                        </td>
                        <td class="px-4 py-3.5 text-right">
                            <RowActions
                                :items="actionsFor(user)"
                                @select="onAction(user, $event)"
                            />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p class="mt-4 text-xs text-zinc-600">
            Manage domains under
            <Link
                :href="route('domains')"
                class="text-cyan-400 hover:text-cyan-300"
                >Domains</Link
            >
            before assigning new addresses.
        </p>

        <!-- Create / Edit -->
        <Modal
            :show="showForm"
            :title="editing ? 'Edit user' : 'Add user'"
            description="Create a login email (mailbox address), password, and product access."
            max-width="lg"
            @close="showForm = false"
        >
            <div class="space-y-4">
                <div>
                    <label class="mb-1.5 block text-xs text-zinc-500"
                        >Display name</label
                    >
                    <input
                        v-model="form.name"
                        class="md-input"
                        placeholder="Support Desk"
                    />
                </div>

                <div>
                    <label class="mb-1.5 block text-xs text-zinc-500"
                        >Login email</label
                    >
                    <div class="flex gap-2">
                        <input
                            v-model="form.local"
                            class="md-input"
                            placeholder="hello"
                            :disabled="!!editing"
                        />
                        <span class="flex items-center text-zinc-500">@</span>
                        <select
                            v-model="form.domain"
                            class="md-input"
                            :disabled="!!editing"
                        >
                            <option
                                v-for="d in domains"
                                :key="d"
                                :value="d"
                            >
                                {{ d }}
                            </option>
                        </select>
                    </div>
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="mb-1.5 block text-xs text-zinc-500"
                            >Password
                            <span v-if="editing" class="text-zinc-600"
                                >(optional)</span
                            ></label
                        >
                        <input
                            v-model="form.password"
                            type="password"
                            class="md-input"
                            :placeholder="
                                editing ? 'Leave blank to keep' : 'Required'
                            "
                            autocomplete="new-password"
                        />
                    </div>
                    <div>
                        <label class="mb-1.5 block text-xs text-zinc-500"
                            >Confirm password</label
                        >
                        <input
                            v-model="form.password_confirmation"
                            type="password"
                            class="md-input"
                            autocomplete="new-password"
                        />
                    </div>
                </div>

                <div>
                    <label class="mb-1.5 block text-xs text-zinc-500">Role</label>
                    <div class="grid gap-2 sm:grid-cols-3">
                        <button
                            v-for="role in ['staff', 'developer', 'admin']"
                            :key="role"
                            type="button"
                            class="rounded-xl border p-3 text-left transition"
                            :class="
                                form.role === role
                                    ? 'border-cyan-400/50 bg-cyan-400/10'
                                    : 'border-zinc-800 hover:border-zinc-700'
                            "
                            @click="form.role = role"
                        >
                            <div class="text-sm font-medium text-white">
                                {{
                                    role === 'admin'
                                        ? 'Admin'
                                        : role === 'developer'
                                          ? 'Developer'
                                          : 'Staff'
                                }}
                            </div>
                            <div class="mt-1 text-[11px] text-zinc-500">
                                {{ roleHint[role] }}
                            </div>
                        </button>
                    </div>
                </div>

                <div>
                    <div class="mb-2 text-xs text-zinc-500">Feature access</div>
                    <div class="space-y-2">
                        <label
                            class="flex items-center justify-between rounded-xl border border-zinc-800 px-3 py-2.5"
                        >
                            <span class="flex items-center gap-2 text-sm text-zinc-200">
                                <Inbox :size="15" class="text-zinc-400" />
                                Inbox
                                <span class="text-[11px] text-zinc-600"
                                    >Always available when enabled</span
                                >
                            </span>
                            <input
                                v-model="form.inbox"
                                type="checkbox"
                                class="rounded border-zinc-700 bg-zinc-900 text-cyan-400 focus:ring-cyan-400/40"
                            />
                        </label>
                        <label
                            class="flex items-center justify-between rounded-xl border border-zinc-800 px-3 py-2.5"
                        >
                            <span class="flex items-center gap-2 text-sm text-zinc-200">
                                <Send :size="15" class="text-cyan-300" />
                                Transactional
                            </span>
                            <input
                                v-model="form.transactional"
                                type="checkbox"
                                class="rounded border-zinc-700 bg-zinc-900 text-cyan-400 focus:ring-cyan-400/40"
                            />
                        </label>
                        <label
                            class="flex items-center justify-between rounded-xl border border-zinc-800 px-3 py-2.5"
                        >
                            <span class="flex items-center gap-2 text-sm text-zinc-200">
                                <Megaphone :size="15" class="text-violet-300" />
                                Marketing
                            </span>
                            <input
                                v-model="form.marketing"
                                type="checkbox"
                                class="rounded border-zinc-700 bg-zinc-900 text-cyan-400 focus:ring-cyan-400/40"
                            />
                        </label>
                    </div>
                </div>

                <div v-if="form.transactional || form.marketing">
                    <label class="mb-1.5 block text-xs text-zinc-500"
                        >Monthly send limit</label
                    >
                    <input
                        v-model.number="form.limit"
                        type="number"
                        min="0"
                        step="100"
                        class="md-input"
                    />
                </div>
            </div>
            <template #footer>
                <button
                    type="button"
                    class="md-btn-ghost"
                    @click="showForm = false"
                >
                    Cancel
                </button>
                <button type="button" class="md-btn-primary" @click="saveUser">
                    <Mail :size="14" />
                    {{ editing ? 'Save changes' : 'Create user' }}
                </button>
            </template>
        </Modal>

        <Modal
            :show="showDelete"
            title="Delete user?"
            :description="
                deleteTarget
                    ? `“${deleteTarget.email}” will lose mailbox access.`
                    : ''
            "
            @close="showDelete = false"
        >
            <p class="text-sm text-zinc-400">
                Past messages stay in logs. You can recreate the address later.
            </p>
            <template #footer>
                <button
                    type="button"
                    class="md-btn-ghost"
                    @click="showDelete = false"
                >
                    Cancel
                </button>
                <button
                    type="button"
                    class="md-btn rounded-full bg-rose-500 px-3.5 py-2 text-sm font-medium text-white hover:bg-rose-400"
                    @click="confirmDelete"
                >
                    Delete user
                </button>
            </template>
        </Modal>

        <Modal
            :show="showImpersonate"
            title="Log in as user"
            :description="
                impersonateTarget
                    ? `View the workspace as ${impersonateTarget.email} (read-only).`
                    : 'View the workspace as this user (read-only).'
            "
            @close="showImpersonate = false"
        >
            <div class="space-y-4">
                <div
                    v-if="impersonateTarget"
                    class="rounded-xl border border-zinc-800 bg-zinc-900/50 px-3 py-2.5"
                >
                    <div class="text-sm font-medium text-white">
                        {{ impersonateTarget.name }}
                    </div>
                    <div class="font-mono text-xs text-zinc-400">
                        {{ impersonateTarget.email }}
                    </div>
                </div>
                <p v-if="impersonateErrors.user" class="text-xs text-rose-300">
                    {{ impersonateErrors.user }}
                </p>
                <div>
                    <label
                        for="user-impersonate-reason"
                        class="mb-1.5 block text-xs text-zinc-500"
                        >Reason (required, logged)</label
                    >
                    <textarea
                        id="user-impersonate-reason"
                        v-model="impersonateForm.reason"
                        rows="3"
                        maxlength="500"
                        class="md-input"
                        placeholder="e.g. Helping with inbox setup — checking what they see"
                    />
                    <div class="mt-1 flex justify-between text-[11px]">
                        <span class="text-rose-300">{{
                            impersonateErrors.reason || ''
                        }}</span>
                        <span
                            class="tabular-nums"
                            :class="
                                reasonLength && reasonLength < 10
                                    ? 'text-amber-300'
                                    : 'text-zinc-500'
                            "
                            >{{ reasonLength }}/500 · min 10</span
                        >
                    </div>
                </div>
            </div>
            <template #footer>
                <button
                    type="button"
                    class="md-btn-ghost"
                    @click="showImpersonate = false"
                >
                    Cancel
                </button>
                <button
                    type="button"
                    class="md-btn-solid"
                    :disabled="!canStartImpersonation"
                    @click="startImpersonation"
                >
                    <LogIn :size="14" />
                    Log in as {{ impersonateTarget?.email || 'user' }}
                </button>
            </template>
        </Modal>
    </AppLayout>
</template>
