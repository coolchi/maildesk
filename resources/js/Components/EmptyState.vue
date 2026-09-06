<script setup>
defineProps({
    title: { type: String, required: true },
    description: { type: String, default: '' },
});
</script>

<template>
    <div
        class="flex flex-col items-center justify-center rounded-xl border border-dashed border-zinc-800 bg-zinc-950/50 px-6 py-16 text-center"
    >
        <div
            class="mb-5 flex h-16 w-16 items-center justify-center rounded-2xl border border-zinc-800 bg-zinc-950 text-zinc-400"
        >
            <slot name="icon" />
        </div>
        <h3 class="text-lg font-medium text-white">{{ title }}</h3>
        <p v-if="description" class="mt-2 max-w-md text-sm text-zinc-400">
            {{ description }}
        </p>
        <div class="mt-6 flex flex-wrap items-center justify-center gap-2">
            <slot name="actions" />
        </div>
    </div>
</template>
