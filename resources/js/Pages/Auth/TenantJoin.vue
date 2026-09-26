<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    organization: {
        type: Object,
        required: true,
    },
    domains: {
        type: Array,
        default: () => [],
    },
    requiresApproval: {
        type: Boolean,
        default: false,
    },
});

const form = useForm({
    name: '',
    local: '',
    domain: props.domains[0] || '',
    password: '',
    password_confirmation: '',
});

const emailPreview = computed(() => {
    const local = form.local.trim().toLowerCase();
    if (!local || !form.domain) return '';
    return `${local}@${form.domain}`;
});

const submit = () => {
    form.post(route('tenant.join.store'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <GuestLayout>
        <Head :title="`Join ${organization.name}`" />

        <div class="mb-6">
            <h1 class="text-lg font-semibold text-white">
                Join {{ organization.name }}
            </h1>
            <p class="mt-1 text-sm text-zinc-400">
                Create a mailbox sign-in account for this workspace.
                <span v-if="requiresApproval">
                    An admin must approve you before you can log in.
                </span>
            </p>
        </div>

        <form @submit.prevent="submit">
            <div>
                <InputLabel for="name" value="Name" />
                <TextInput
                    id="name"
                    type="text"
                    class="mt-1 block w-full"
                    v-model="form.name"
                    required
                    autofocus
                    autocomplete="name"
                />
                <InputError class="mt-2" :message="form.errors.name" />
            </div>

            <div class="mt-4">
                <InputLabel for="local" value="Email" />
                <div class="mt-1 flex gap-2">
                    <TextInput
                        id="local"
                        type="text"
                        class="block min-w-0 flex-1"
                        v-model="form.local"
                        required
                        autocomplete="username"
                        placeholder="you"
                    />
                    <span
                        class="flex shrink-0 items-center text-sm text-zinc-500"
                        >@</span
                    >
                    <select
                        v-model="form.domain"
                        class="md-input w-auto min-w-[8rem] shrink-0"
                        required
                    >
                        <option
                            v-for="domain in domains"
                            :key="domain"
                            :value="domain"
                        >
                            {{ domain }}
                        </option>
                    </select>
                </div>
                <p
                    v-if="emailPreview"
                    class="mt-1.5 font-mono text-xs text-zinc-500"
                >
                    {{ emailPreview }}
                </p>
                <InputError class="mt-2" :message="form.errors.local" />
                <InputError class="mt-2" :message="form.errors.domain" />
            </div>

            <div class="mt-4">
                <InputLabel for="password" value="Password" />
                <TextInput
                    id="password"
                    type="password"
                    class="mt-1 block w-full"
                    v-model="form.password"
                    required
                    autocomplete="new-password"
                />
                <InputError class="mt-2" :message="form.errors.password" />
            </div>

            <div class="mt-4">
                <InputLabel
                    for="password_confirmation"
                    value="Confirm Password"
                />
                <TextInput
                    id="password_confirmation"
                    type="password"
                    class="mt-1 block w-full"
                    v-model="form.password_confirmation"
                    required
                    autocomplete="new-password"
                />
                <InputError
                    class="mt-2"
                    :message="form.errors.password_confirmation"
                />
            </div>

            <div class="mt-6 flex items-center justify-between gap-3">
                <Link
                    :href="route('login')"
                    class="rounded-md text-sm text-zinc-400 underline hover:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                >
                    Already have an account?
                </Link>

                <PrimaryButton
                    :class="{ 'opacity-25': form.processing }"
                    :disabled="form.processing"
                >
                    {{ requiresApproval ? 'Request access' : 'Create account' }}
                </PrimaryButton>
            </div>
        </form>
    </GuestLayout>
</template>
