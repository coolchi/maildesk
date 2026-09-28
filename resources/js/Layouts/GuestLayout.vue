<script setup>
import { Link } from '@inertiajs/vue3';
import { useTheme } from '@/composables/useTheme';
import BrandLogo from '@/Components/BrandLogo.vue';
import { Moon, Sun } from '@lucide/vue';

const { theme, setTheme } = useTheme();
</script>

<template>
    <div
        class="relative flex min-h-screen flex-col items-center justify-center overflow-hidden bg-black px-4 py-12"
    >
        <div
            class="pointer-events-none absolute left-1/2 top-[42%] h-[32rem] w-[32rem] -translate-x-1/2 -translate-y-1/2 rounded-full bg-cyan-400/10 blur-3xl"
            aria-hidden="true"
        />

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

        <div class="relative flex w-full max-w-md flex-col items-center">
            <Link href="/" class="mb-8 flex items-center" aria-label="MailDesk home">
                <BrandLogo class="h-10" />
            </Link>

            <div
                class="w-full rounded-2xl border border-zinc-800 bg-zinc-950 px-7 py-8 shadow-panel"
            >
                <slot />
            </div>
        </div>
    </div>
</template>
