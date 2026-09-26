<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';
import { useToast } from '@/composables/useToast';
import { Trash2 } from '@lucide/vue';

const props = defineProps({
    account: { type: Object, required: true },
});

const toast = useToast();
const open = ref(false);
const deleting = ref(false);
const errors = ref({});
const form = ref({ confirmation: '', password: '' });

const expected = computed(() => props.account.subdomain || props.account.name);

const canDelete = computed(() => {
    const typed = form.value.confirmation.trim().toLowerCase();
    const accepted = [props.account.subdomain, props.account.name]
        .filter(Boolean)
        .map((v) => String(v).toLowerCase());
    return accepted.includes(typed) && form.value.password.length > 0 && !deleting.value;
});

const openModal = () => {
    form.value = { confirmation: '', password: '' };
    errors.value = {};
    open.value = true;
};

const destroy = () => {
    if (!canDelete.value) return;
    router.delete(route('admin.accounts.destroy', props.account.id), {
        data: { ...form.value },
        preserveScroll: true,
        onStart: () => (deleting.value = true),
        onFinish: () => (deleting.value = false),
        onSuccess: () => {
            open.value = false;
            toast.success(`Account ${props.account.name} deleted.`);
        },
        onError: (e) => {
            errors.value = e;
            toast.error(e.confirmation || e.password || 'Could not delete account.');
        },
    });
};
</script>

<template>
    <section class="md-card mb-6 border-rose-500/30 p-5" data-testid="delete-account-panel">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="text-sm font-medium text-white">Delete account</h2>
                <p class="mt-1 text-xs text-zinc-500">
                    Closes the workspace: members can no longer sign in or send, active
                    subscriptions are canceled and it disappears from admin lists. Data is
                    kept (soft delete).
                </p>
            </div>
            <button type="button" class="md-btn-ghost text-rose-300" @click="openModal">
                <Trash2 :size="16" />
                Delete account
            </button>
        </div>

        <Modal
            :show="open"
            title="Delete this account?"
            :description="`Type ${expected} and your password to confirm.`"
            @close="open = false"
        >
            <div class="space-y-3">
                <div>
                    <label class="mb-1.5 block text-xs text-zinc-500">
                        Type <span class="font-mono text-zinc-300">{{ expected }}</span>
                    </label>
                    <input v-model="form.confirmation" class="md-input" autocomplete="off" />
                    <p v-if="errors.confirmation" class="mt-1 text-xs text-rose-300">
                        {{ errors.confirmation }}
                    </p>
                </div>
                <div>
                    <label class="mb-1.5 block text-xs text-zinc-500">Your password</label>
                    <input
                        v-model="form.password"
                        type="password"
                        class="md-input"
                        autocomplete="current-password"
                    />
                    <p v-if="errors.password" class="mt-1 text-xs text-rose-300">
                        {{ errors.password }}
                    </p>
                </div>
            </div>
            <template #footer>
                <button type="button" class="md-btn-ghost" @click="open = false">Cancel</button>
                <button
                    type="button"
                    class="md-btn-solid !bg-rose-500 hover:!bg-rose-400"
                    :disabled="!canDelete"
                    @click="destroy"
                >
                    <Trash2 :size="14" />
                    {{ deleting ? 'Deleting…' : 'Delete account' }}
                </button>
            </template>
        </Modal>
    </section>
</template>
