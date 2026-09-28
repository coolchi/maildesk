<script setup>
defineProps({
    tone: {
        type: String,
        default: 'auto',
    },
});
</script>

<template>
    <span class="brand-logo" :class="`is-${tone}`" role="img" aria-label="MailDesk">
        <img src="/images/brand/logo-dark.png" alt="" class="brand-logo__img brand-logo__dark" />
        <img src="/images/brand/logo-light.png" alt="" class="brand-logo__img brand-logo__light" />
    </span>
</template>
