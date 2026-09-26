<script setup>
import { onMounted, ref } from 'vue';
import Modal from '@/Components/Modal.vue';
import { useToast } from '@/composables/useToast';
import { Shield, UserPlus, Users, X } from '@lucide/vue';

const props = defineProps({
    accountId: { type: [String, Number], required: true },
});

const toast = useToast();
const users = ref([]);
const loading = ref(true);
const busy = ref(false);
const showAdd = ref(false);
const addForm = ref({ email: '', name: '', role: 'member' });
const addErrors = ref({});
const roles = ['owner', 'admin', 'member'];

const firstError = (error, fallback) => {
    const data = error?.response?.data;
    if (data?.errors) {
        const first = Object.values(data.errors)[0];
        return Array.isArray(first) ? first[0] : first;
    }
    return data?.message || fallback;
};

const load = async () => {
    loading.value = true;
    try {
        const { data } = await window.axios.get(route('admin.accounts.users', props.accountId));
        users.value = data.users;
    } catch (e) {
        toast.error(firstError(e, 'Could not load users.'));
    } finally {
        loading.value = false;
    }
};

const addUser = async () => {
    busy.value = true;
    addErrors.value = {};
    try {
        const { data } = await window.axios.post(
            route('admin.accounts.users.store', props.accountId),
            addForm.value,
        );
        users.value = data.users;
        showAdd.value = false;
        toast.success(data.message);
    } catch (e) {
        addErrors.value = e?.response?.data?.errors || {};
        toast.error(firstError(e, 'Could not add user.'));
    } finally {
        busy.value = false;
    }
};

const changeRole = async (user, role) => {
    if (role === user.role) return;
    busy.value = true;
    try {
        const { data } = await window.axios.put(
            route('admin.accounts.users.update', [props.accountId, user.id]),
            { role },
        );
        users.value = data.users;
        toast.success(data.message);
    } catch (e) {
        toast.error(firstError(e, 'Could not change role.'));
        await load();
    } finally {
        busy.value = false;
    }
};

const removeUser = async (user) => {
    if (!window.confirm(`Remove ${user.email} from this account?`)) return;
    busy.value = true;
    try {
        const { data } = await window.axios.delete(
            route('admin.accounts.users.destroy', [props.accountId, user.id]),
        );
        users.value = data.users;
        toast.success(data.message);
    } catch (e) {
        toast.error(firstError(e, 'Could not remove user.'));
    } finally {
        busy.value = false;
    }
};

const openAdd = () => {
    addForm.value = { email: '', name: '', role: 'member' };
    addErrors.value = {};
    showAdd.value = true;
};

onMounted(load);
</script>

<template>
    <section class="md-card mb-6 p-5" data-testid="account-users-panel">
        <div class="mb-4 flex items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <Users :size="16" class="text-zinc-500" />
                <h2 class="text-sm font-medium text-white">Users</h2>
            </div>
            <button type="button" class="md-btn-ghost" @click="openAdd">
                <UserPlus :size="16" />
                Add user
            </button>
        </div>

        <p v-if="loading" class="text-sm text-zinc-500">Loading users…</p>
        <p v-else-if="!users.length" class="text-sm text-zinc-500">This account has no users.</p>
        <ul v-else class="divide-y divide-zinc-900">
            <li
                v-for="u in users"
                :key="u.id"
                class="flex flex-wrap items-center justify-between gap-3 py-2.5"
            >
                <div class="min-w-0">
                    <div class="flex items-center gap-2 text-sm text-white">
                        <span class="truncate">{{ u.name }}</span>
                        <span
                            v-if="u.is_platform_admin"
                            class="inline-flex items-center gap-1 rounded-full bg-amber-400/10 px-2 py-0.5 text-[10px] text-amber-300"
                        >
                            <Shield :size="10" /> Platform admin
                        </span>
                    </div>
                    <div class="truncate text-xs text-zinc-500">
                        {{ u.email }}<span v-if="u.joined"> · joined {{ u.joined }}</span>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <select
                        class="md-input !w-auto !py-1 capitalize"
                        :value="u.role"
                        :disabled="busy || u.is_platform_admin || u.is_last_owner"
                        :title="
                            u.is_platform_admin
                                ? 'Platform admins cannot be changed here'
                                : u.is_last_owner
                                  ? 'Last owner — add another owner first'
                                  : 'Change role'
                        "
                        @change="changeRole(u, $event.target.value)"
                    >
                        <option v-for="r in roles" :key="r" :value="r">{{ r }}</option>
                    </select>
                    <button
                        type="button"
                        class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-zinc-800 text-zinc-500 transition hover:border-rose-500/40 hover:text-rose-400 disabled:opacity-40"
                        :disabled="busy || u.is_platform_admin || u.is_last_owner"
                        title="Remove from account"
                        @click="removeUser(u)"
                    >
                        <X :size="14" />
                    </button>
                </div>
            </li>
        </ul>

        <Modal
            :show="showAdd"
            title="Add user"
            description="Add an existing user by email, or create a new one. New users get a random password (not shown or emailed) and set their own with “Forgot password” on the login page."
            @close="showAdd = false"
        >
            <div class="space-y-3">
                <div>
                    <label class="mb-1.5 block text-xs text-zinc-500">Email</label>
                    <input v-model="addForm.email" type="email" class="md-input" />
                    <p v-if="addErrors.email" class="mt-1 text-xs text-rose-300">
                        {{ addErrors.email[0] }}
                    </p>
                </div>
                <div>
                    <label class="mb-1.5 block text-xs text-zinc-500">
                        Name (required only for new users)
                    </label>
                    <input v-model="addForm.name" class="md-input" />
                    <p v-if="addErrors.name" class="mt-1 text-xs text-rose-300">
                        {{ addErrors.name[0] }}
                    </p>
                </div>
                <div>
                    <label class="mb-1.5 block text-xs text-zinc-500">Role</label>
                    <select v-model="addForm.role" class="md-input capitalize">
                        <option v-for="r in roles" :key="r" :value="r">{{ r }}</option>
                    </select>
                </div>
            </div>
            <template #footer>
                <button type="button" class="md-btn-ghost" @click="showAdd = false">Cancel</button>
                <button type="button" class="md-btn-solid" :disabled="busy" @click="addUser">
                    <UserPlus :size="14" />
                    {{ busy ? 'Adding…' : 'Add user' }}
                </button>
            </template>
        </Modal>
    </section>
</template>
