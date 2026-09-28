<script setup>
import { useDesigns } from '@/composables/useDesigns';

defineProps({
    compact: { type: Boolean, default: false },
    plainLabel: { type: String, default: 'Plain mail' },
});

const model = defineModel({ type: String, default: '' });
const { catalog } = useDesigns();
</script>

<template>
    <label class="block min-w-0">
        <span
            v-if="!compact"
            class="mb-1.5 flex items-center justify-between gap-2 text-xs text-zinc-500"
        >
            Design
            <span class="font-normal text-zinc-600">Optional</span>
        </span>
        <select
            v-model="model"
            class="md-input"
            :class="compact ? '!w-auto !py-1.5 text-xs' : ''"
            data-testid="design-picker"
        >
            <option value="">{{ plainLabel }}</option>
            <option v-for="design in catalog" :key="design.key" :value="design.key">
                {{ design.name }}
            </option>
        </select>
    </label>
</template>
