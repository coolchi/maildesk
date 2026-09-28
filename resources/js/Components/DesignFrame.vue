<script setup>
import { computed } from 'vue';
import { useDesigns } from '@/composables/useDesigns';

const props = defineProps({
    design: { type: String, default: '' },
});

const { brand, find } = useDesigns();
const active = computed(() => find(props.design));

const labelStyle = computed(() => {
    const design = active.value;
    if (!design) return {};

    const serif = design.key === 'editorial';

    return {
        color: design.accent,
        fontFamily: serif ? 'Georgia, Times New Roman, serif' : 'inherit',
    };
});
</script>

<template>
    <div v-if="!active">
        <slot />
    </div>
    <div
        v-else
        class="overflow-hidden rounded-xl p-3 sm:p-4"
        :style="{ background: active.background }"
        data-testid="design-frame"
    >
        <div
            class="mb-2 px-1 text-[11px] font-semibold uppercase tracking-[0.16em]"
            :style="labelStyle"
        >
            {{ brand || 'MailDesk' }}
        </div>
        <div
            :class="
                active.key === 'signal'
                    ? 'border-l-4 pl-1'
                    : active.key === 'midnight'
                      ? 'rounded-xl border-t-[3px] p-1'
                      : active.key === 'editorial'
                        ? 'border-t pt-3'
                        : ''
            "
            :style="
                active.key === 'signal' || active.key === 'midnight'
                    ? { borderColor: active.accent }
                    : active.key === 'editorial'
                      ? { borderColor: active.accent }
                      : undefined
            "
        >
            <slot />
        </div>
        <p
            v-if="active.key === 'aurora'"
            class="mt-2 px-1 text-[11px]"
            style="color: #94a3b8"
        >
            Sent by {{ brand || 'MailDesk' }}
        </p>
    </div>
</template>
