<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';

const props = defineProps({
    href: { type: String, required: true },
    // undefined = derive from URL; boolean = parent-controlled (incl. false)
    active: { type: Boolean, default: undefined },
    badge: { type: [Number, String], default: null },
});

const page = usePage();

const isActive = computed(() => {
    if (props.active !== undefined && props.active !== null) {
        return props.active;
    }
    try {
        const current = page.url.split('?')[0];
        const target = new URL(props.href, 'http://local').pathname;
        return current === target || current.startsWith(target + '/');
    } catch {
        return false;
    }
});

const showBadge = computed(() => {
    if (
        props.badge === null ||
        props.badge === undefined ||
        props.badge === ''
    ) {
        return false;
    }
    return Number(props.badge) > 0 || typeof props.badge === 'string';
});
</script>

<template>
    <Link
        :href="href"
        class="group relative flex items-center gap-2.5 rounded-lg px-2.5 py-2 text-[13px] transition-all duration-200"
        :class="
            isActive
                ? 'bg-zinc-800/90 text-white shadow-sm'
                : 'text-zinc-400 hover:bg-zinc-900 hover:text-zinc-100'
        "
    >
        <span
            v-if="isActive"
            class="absolute left-0 top-1/2 h-5 w-0.5 -translate-y-1/2 rounded-r bg-cyan-400"
        />
        <span
            class="flex h-4 w-4 shrink-0 items-center justify-center transition-transform duration-200 group-hover:scale-110"
            :class="
                isActive
                    ? 'text-cyan-400'
                    : 'text-zinc-500 group-hover:text-cyan-300'
            "
        >
            <slot name="icon" />
        </span>
        <span class="min-w-0 flex-1 truncate transition-colors"><slot /></span>
        <span
            v-if="showBadge"
            class="ml-auto flex h-5 min-w-5 shrink-0 items-center justify-center rounded-full bg-cyan-400 px-1.5 text-[10px] font-semibold text-zinc-950"
        >
            {{ Number(badge) > 9 ? '9+' : badge }}
        </span>
    </Link>
</template>
