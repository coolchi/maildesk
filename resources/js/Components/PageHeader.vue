<script setup>
defineProps({
    title: { type: String, required: true },
    description: { type: String, default: '' },
});
</script>

<template>
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div class="animate-slide-up">
            <h1 class="text-2xl font-semibold tracking-tight text-white">
                {{ title }}
            </h1>
            <p v-if="description" class="mt-1 max-w-2xl text-sm text-zinc-400">
                {{ description }}
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2 animate-fade-in">
            <slot name="actions" />
        </div>
    </div>
</template>
