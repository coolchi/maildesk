<script setup>
import { computed } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ShieldAlert } from '@lucide/vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';

const props = defineProps({
    status: {
        type: Number,
        required: true,
    },
    title: {
        type: String,
        required: true,
    },
    message: {
        type: String,
        required: true,
    },
});

const page = usePage();
const signedIn = computed(() => Boolean(page.props.auth?.user));

const signOut = () => {
    router.post(route('logout'), {
        _token: page.props.csrf_token,
    });
};
</script>

<template>
    <Head :title="title" />

    <AppLayout v-if="signedIn">
        <div class="mx-auto flex min-h-[50vh] max-w-lg items-center">
            <div class="md-card w-full p-8 text-center">
                <div
                    class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-amber-500/10 text-amber-300"
                >
                    <ShieldAlert :size="22" />
                </div>
                <p class="mt-4 text-sm font-medium text-cyan-300">{{ status }}</p>
                <h1 class="mt-1 text-lg font-semibold text-white">{{ title }}</h1>
                <p class="mt-3 text-sm leading-relaxed text-zinc-400">{{ message }}</p>
                <div class="mt-6 flex flex-wrap items-center justify-center gap-3">
                    <Link :href="route('profile.edit')" class="md-btn-ghost">
                        Profile
                    </Link>
                    <button type="button" class="md-btn-primary" @click="signOut">
                        Log out
                    </button>
                </div>
            </div>
        </div>
    </AppLayout>

    <GuestLayout v-else>
        <div class="text-center">
            <div
                class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-amber-500/10 text-amber-300"
            >
                <ShieldAlert :size="22" />
            </div>
            <p class="mt-4 text-sm font-medium text-cyan-300">{{ props.status }}</p>
            <h1 class="mt-1 text-lg font-semibold text-white">{{ title }}</h1>
            <p class="mt-3 text-sm leading-relaxed text-zinc-400">{{ message }}</p>
            <Link :href="route('login')" class="md-btn-primary mt-6">Log in</Link>
        </div>
    </GuestLayout>
</template>
