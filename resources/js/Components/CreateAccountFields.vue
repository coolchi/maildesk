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
    const value = event.target.value;
    subdomain.value = value;
    subdomainTouched.value = value !== '';
};

const preview = computed(() => {
    const label = (subdomain.value || '').trim().toLowerCase() || 'your-subdomain';
    return props.baseDomain ? `${label}.${props.baseDomain}` : label;
});

const reset = () => {
    subdomainTouched.value = false;
};

defineExpose({ reset, subdomainTouched });
</script>

<template>
    <div class="space-y-3">
        <div>
            <input
                :value="name"
                class="md-input"
                placeholder="Team name"
                data-test="team-name"
                @input="onNameInput"
                @keyup.enter="emit('submit')"
            />
            <p v-if="errors.name" class="mt-1 text-xs text-rose-400">{{ errors.name }}</p>
        </div>
        <div>
            <label for="create-account-subdomain" class="mb-1.5 block text-xs text-zinc-500">Subdomain</label>
            <input
                id="create-account-subdomain"
                :value="subdomain"
                class="md-input"
                placeholder="acme"
                autocapitalize="off"
                autocomplete="off"
                spellcheck="false"
                :maxlength="SUBDOMAIN_MAX_LENGTH"
                data-test="subdomain"
                @input="onSubdomainInput"
                @keyup.enter="emit('submit')"
            />
            <p class="mt-1.5 text-xs text-zinc-500">
                Your address:
                <span class="font-mono text-zinc-300" data-test="address-preview">{{ preview }}</span>
            </p>
            <p v-if="errors.subdomain" class="mt-1 text-xs text-rose-400">{{ errors.subdomain }}</p>
        </div>
    </div>
</template>
