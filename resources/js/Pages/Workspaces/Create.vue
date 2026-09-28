<script setup>
import CreateAccountFields from '@/Components/CreateAccountFields.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const baseDomain = computed(() => page.props.tenant?.base_domain ?? '');
const email = computed(() => page.props.auth?.user?.email ?? '');

const form = useForm({
    name: '',
    subdomain: '',
});

const canSubmit = computed(
    () => form.name.trim().length > 0 && form.subdomain.trim().length >= 3,
);

const submit = () => {
    form.post(route('workspaces.store'));
};

const logout = () => {
    router.post(route('logout'));
};
</script>

<template>
    <GuestLayout>
        <Head title="Create your workspace" />

        <div class="mb-6 text-center">
            <h1 class="text-lg font-semibold text-white">Create your workspace</h1>
            <p class="mt-1.5 text-sm leading-relaxed text-zinc-400">
                This is your team's mail. The address below is where you sign in.
            </p>
            <p v-if="email" class="mt-3 text-xs text-zinc-500">
                Signed in as <span class="text-zinc-300">{{ email }}</span>
            </p>
        </div>

        <form class="space-y-4" @submit.prevent="submit">
            <CreateAccountFields
                v-model:name="form.name"
                v-model:subdomain="form.subdomain"
                :base-domain="baseDomain"
                :errors="form.errors"
                @submit="submit"
            />

            <PrimaryButton
                class="mt-2 w-full"
                :class="{ 'opacity-25': form.processing || !canSubmit }"
                :disabled="form.processing || !canSubmit"
            >
                Create workspace
            </PrimaryButton>
        </form>

        <p class="mt-5 text-center text-sm text-zinc-400">
            <button
                type="button"
                class="font-medium text-cyan-300 hover:text-cyan-200"
                @click="logout"
            >
                Sign out
            </button>
        </p>
    </GuestLayout>
</template>
