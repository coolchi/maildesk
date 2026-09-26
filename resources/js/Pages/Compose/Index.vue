<script setup>
import { onMounted } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { useComposeModal } from '@/composables/useComposeModal';

const { open } = useComposeModal();

onMounted(() => {
    open();
    router.visit(route('sent'), { replace: true });
});
</script>

<template>
    <Head title="Compose" />
</template>
