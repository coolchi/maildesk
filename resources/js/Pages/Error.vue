<script setup>
import { computed } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    Ban,
    CloudOff,
    Clock,
    SearchX,
    ServerCrash,
    ShieldAlert,
    TimerOff,
} from '@lucide/vue';
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

const appearance = computed(() => {
    const map = {
        403: { icon: ShieldAlert, wrap: 'bg-amber-500/10 text-amber-300' },
        404: { icon: SearchX, wrap: 'bg-cyan-400/10 text-cyan-300' },
        405: { icon: Ban, wrap: 'bg-amber-500/10 text-amber-300' },
        419: { icon: Clock, wrap: 'bg-amber-500/10 text-amber-300' },
        429: { icon: TimerOff, wrap: 'bg-amber-500/10 text-amber-300' },
        500: { icon: ServerCrash, wrap: 'bg-rose-500/10 text-rose-300' },
        503: { icon: CloudOff, wrap: 'bg-amber-500/10 text-amber-300' },
    };

    return map[props.status] ?? map[500];
});

const goBack = () => {
    if (window.history.length > 1) {
        window.history.back();

        return;
    }

    router.visit(signedIn.value ? route('dashboard') : route('login'));
};

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
                    class="mx-auto flex h-12 w-12 items-center justify-center rounded-full"
                    :class="appearance.wrap"
                >
                    <component :is="appearance.icon" :size="22" />
                </div>
                <p class="mt-4 text-sm font-medium text-cyan-300">{{ status }}</p>
                <h1 class="mt-1 text-lg font-semibold text-white">{{ title }}</h1>
                <p class="mt-3 text-sm leading-relaxed text-zinc-400">{{ message }}</p>
                <div class="mt-6 flex flex-wrap items-center justify-center gap-3">
                    <template v-if="status === 403">
                        <Link :href="route('profile.edit')" class="md-btn-ghost">
                            Profile
                        </Link>
                        <button type="button" class="md-btn-primary" @click="signOut">
                            Log out
                        </button>
                    </template>
                    <template v-else>
                        <button type="button" class="md-btn-ghost" @click="goBack">
                            Go back
                        </button>
                        <Link :href="route('dashboard')" class="md-btn-primary">
                            Go to dashboard
                        </Link>
                    </template>
                </div>
            </div>
        </div>
    </AppLayout>

    <GuestLayout v-else>
        <div class="text-center">
            <div
                class="mx-auto flex h-12 w-12 items-center justify-center rounded-full"
                :class="appearance.wrap"
            >
                <component :is="appearance.icon" :size="22" />
            </div>
            <p class="mt-4 text-sm font-medium text-cyan-300">{{ status }}</p>
            <h1 class="mt-1 text-lg font-semibold text-white">{{ title }}</h1>
            <p class="mt-3 text-sm leading-relaxed text-zinc-400">{{ message }}</p>
            <div class="mt-6 flex flex-wrap items-center justify-center gap-3">
                <button type="button" class="md-btn-ghost" @click="goBack">
                    Go back
                </button>
                <Link :href="route('login')" class="md-btn-primary">Log in</Link>
            </div>
        </div>
    </GuestLayout>
</template>
