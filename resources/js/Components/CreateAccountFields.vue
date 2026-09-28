<script setup>
import { computed, ref } from 'vue';
import { slugify, SUBDOMAIN_MAX_LENGTH } from '@/lib/slugify';

const props = defineProps({
    baseDomain: { type: String, default: '' },
    errors: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['submit']);

const name = defineModel('name', { type: String, default: '' });
const subdomain = defineModel('subdomain', { type: String, default: '' });

// Auto-fill the subdomain from the name until the user edits it by hand.
// Clearing the subdomain field re-enables auto-fill.
const subdomainTouched = ref(false);

const onNameInput = (event) => {
    const value = event.target.value;
    name.value = value;
    if (!subdomainTouched.value) {
        subdomain.value = slugify(value);
    }
};

const onSubdomainInput = (event) => {
    const value = String(event.target.value)
        .toLowerCase()
        .replace(/[^a-z0-9-]/g, '')
        .slice(0, SUBDOMAIN_MAX_LENGTH);
    subdomain.value = value;
    subdomainTouched.value = value !== '';
};

const slug = computed(() => (subdomain.value || '').trim().toLowerCase());

const preview = computed(() => {
    if (!slug.value) return '';
    return props.baseDomain ? `${slug.value}.${props.baseDomain}` : slug.value;
});

const reset = () => {
    subdomainTouched.value = false;
};

defineExpose({ reset, subdomainTouched });
</script>

<template>
    <div class="space-y-4">
        <div>
            <label for="create-account-name" class="mb-1.5 block text-sm font-medium text-zinc-300">
                Workspace name
            </label>
            <input
                id="create-account-name"
                :value="name"
                class="md-input py-2.5"
                placeholder="Acme"
                autocomplete="organization"
                autofocus
                data-test="team-name"
                @input="onNameInput"
                @keyup.enter="emit('submit')"
            />
            <p v-if="errors.name" class="mt-1.5 text-xs text-rose-400">{{ errors.name }}</p>
        </div>

        <div>
            <label for="create-account-subdomain" class="mb-1.5 block text-sm font-medium text-zinc-300">
                Address
            </label>
            <div
                class="flex items-stretch overflow-hidden rounded-lg border border-zinc-800 bg-zinc-950 shadow-sm focus-within:border-cyan-400/60 focus-within:ring-1 focus-within:ring-cyan-400/40"
            >
                <input
                    id="create-account-subdomain"
                    :value="subdomain"
                    class="min-w-0 flex-1 border-0 bg-transparent px-3 py-2.5 text-sm text-zinc-100 placeholder:text-zinc-500 focus:outline-none focus:ring-0"
                    placeholder="acme"
                    autocapitalize="off"
                    autocomplete="off"
                    spellcheck="false"
                    :maxlength="SUBDOMAIN_MAX_LENGTH"
                    data-test="subdomain"
                    @input="onSubdomainInput"
                    @keyup.enter="emit('submit')"
                />
                <span
                    v-if="baseDomain"
                    class="flex items-center border-l border-zinc-800 bg-zinc-900 px-3 text-sm text-zinc-400"
                >
                    .{{ baseDomain }}
                </span>
            </div>
            <p class="mt-1.5 text-xs text-zinc-500">
                <template v-if="preview">
                    Sign in at
                    <span class="font-mono text-cyan-300" data-test="address-preview">{{ preview }}</span>
                </template>
                <template v-else>
                    Letters, numbers, and hyphens. At least 3 characters.
                    <span class="sr-only" data-test="address-preview" />
                </template>
            </p>
            <p v-if="errors.subdomain" class="mt-1.5 text-xs text-rose-400">{{ errors.subdomain }}</p>
        </div>
    </div>
</template>
