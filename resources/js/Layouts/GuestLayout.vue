<script setup>
import { Link } from '@inertiajs/vue3';
import { useTheme } from '@/composables/useTheme';
import { Moon, Sun } from '@lucide/vue';

const { theme, setTheme } = useTheme();
</script>

<template>
    <div
        class="relative flex min-h-screen flex-col items-center justify-center bg-black px-4 py-10"
    >
        <div class="absolute right-4 top-4">
            <div
                class="inline-flex rounded-full border border-zinc-800 bg-zinc-950 p-0.5"
            >
                <button
                    type="button"
                    class="rounded-full p-1.5 transition"
                    :class="
                        theme === 'light'
                            ? 'bg-zinc-800 text-cyan-300'
                            : 'text-zinc-500 hover:text-zinc-300'
                    "
                    title="Light"
                    @click="setTheme('light')"
                >
                    <Sun :size="14" />
                </button>
                <button
                    type="button"
                    class="rounded-full p-1.5 transition"
                    :class="
                        theme === 'dark'
                            ? 'bg-zinc-800 text-cyan-300'
                            : 'text-zinc-500 hover:text-zinc-300'
                    "
                    title="Dark"
                    @click="setTheme('dark')"
                >
                    <Moon :size="14" />
                </button>
            </div>
        </div>

        <Link
            href="/"
            class="mb-8 text-xl font-semibold tracking-tight text-white"
        >
            MailDesk
        </Link>

        <div
            class="w-full max-w-md rounded-xl border border-zinc-800 bg-zinc-950 px-6 py-6 shadow-panel"
        >
            <slot />
        </div>
    </div>
</template>
