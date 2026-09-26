<script setup>
import { computed } from 'vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    status: {
        type: String,
    },
});

const form = useForm({});

const submit = () => {
    form.post(route('verification.send'));
};

const verificationLinkSent = computed(
    () => props.status === 'verification-link-sent',
);
</script>

<template>
    <GuestLayout>
        <Head title="Email Verification" />

        <div class="mb-6 text-center">
            <h1 class="text-lg font-semibold text-white">Verify your email</h1>
            <p class="mt-1.5 text-sm leading-relaxed text-zinc-400">
                Click the link we emailed you. If it didn't arrive, we can send
                another.
            </p>
        </div>

        <div
            class="mb-5 rounded-lg border border-cyan-400/40 bg-cyan-400/10 px-3 py-2 text-sm text-cyan-300"
            v-if="verificationLinkSent"
        >
            A new verification link has been sent to the email address you
            provided during registration.
        </div>

        <form class="space-y-4" @submit.prevent="submit">
            <PrimaryButton
                class="w-full"
                :class="{ 'opacity-25': form.processing }"
                :disabled="form.processing"
            >
                Resend verification email
            </PrimaryButton>

            <div class="text-center">
                <Link
                    :href="route('logout')"
                    method="post"
                    as="button"
                    class="text-sm text-zinc-400 hover:text-cyan-300"
                >
                    Log out
                </Link>
            </div>
        </form>
    </GuestLayout>
</template>
