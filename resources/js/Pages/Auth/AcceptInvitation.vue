<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps({
    invitation: {
        type: Object,
        required: true,
    },
});

const form = useForm({
    name: '',
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.post(route('invitations.accept.store', props.invitation.token), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <GuestLayout>
        <Head :title="`Join ${invitation.organization}`" />

        <div class="mb-6">
            <h1 class="text-lg font-semibold text-white">
                Join {{ invitation.organization }}
            </h1>
            <p class="mt-1 text-sm text-zinc-400">
                <span v-if="invitation.inviter">{{ invitation.inviter }}</span>
                <span v-else>A teammate</span>
                invited
                <span class="text-zinc-200">{{ invitation.email }}</span>
                as
                <span class="capitalize text-zinc-200">{{ invitation.role }}</span>.
            </p>
        </div>

        <form v-if="invitation.needs_account" class="space-y-4" @submit.prevent="submit">
            <div>
                <InputLabel for="name" value="Your name" />
                <TextInput
                    id="name"
                    v-model="form.name"
                    type="text"
                    class="mt-1 block w-full"
                    required
                    autofocus
                    autocomplete="name"
                />
                <InputError class="mt-2" :message="form.errors.name" />
            </div>

            <div>
                <InputLabel for="password" value="Password" />
                <TextInput
                    id="password"
                    v-model="form.password"
                    type="password"
                    class="mt-1 block w-full"
                    required
                    autocomplete="new-password"
                />
                <InputError class="mt-2" :message="form.errors.password" />
            </div>

            <div>
                <InputLabel
                    for="password_confirmation"
                    value="Confirm password"
                />
                <TextInput
                    id="password_confirmation"
                    v-model="form.password_confirmation"
                    type="password"
                    class="mt-1 block w-full"
                    required
                    autocomplete="new-password"
                />
            </div>

            <PrimaryButton
                class="w-full justify-center"
                :disabled="form.processing"
            >
                Create account &amp; join
            </PrimaryButton>
        </form>

        <form v-else class="space-y-4" @submit.prevent="submit">
            <p class="text-sm text-zinc-400">
                You already have a MailDesk account for this email. Accept to join
                the workspace.
            </p>
            <PrimaryButton
                class="w-full justify-center"
                :disabled="form.processing"
            >
                Accept invitation
            </PrimaryButton>
        </form>
    </GuestLayout>
</template>
