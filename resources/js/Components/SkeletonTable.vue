<script setup>
defineProps({
    rows: { type: Number, default: 5 },
    cols: { type: Number, default: 4 },
});
</script>

<template>
    <div class="md-table-wrap animate-pulse">
        <div class="border-b border-zinc-800 px-4 py-3">
            <div class="h-3 w-40 rounded bg-zinc-800" />
        </div>
        <div
            v-for="r in rows"
            :key="r"
            class="grid gap-4 border-b border-zinc-900 px-4 py-4 last:border-0"
            :style="{ gridTemplateColumns: `repeat(${cols}, minmax(0, 1fr))` }"
        >
            <div
                v-for="c in cols"
                :key="c"
                class="h-3 rounded bg-zinc-800/80"
                :class="c === 1 ? 'w-3/4' : 'w-1/2'"
            />
        </div>
    </div>
</template>
