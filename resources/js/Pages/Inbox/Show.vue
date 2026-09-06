<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    thread: Object,
});
</script>

<template>
    <Head :title="thread.subject" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <Link
                    :href="route('inbox')"
                    class="text-sm text-gray-500 hover:text-gray-800"
                >
                    ← Inbox
                </Link>
                <h2 class="text-xl font-semibold leading-tight text-gray-800">
                    {{ thread.subject }}
                </h2>
            </div>
        </template>

        <div class="py-10">
            <div class="mx-auto max-w-3xl space-y-4 sm:px-6 lg:px-8">
                <article
                    v-for="message in thread.messages"
                    :key="message.id"
                    class="bg-white p-6 shadow-sm sm:rounded-lg"
                >
                    <div class="mb-4 flex flex-wrap items-center justify-between gap-2 text-sm">
                        <div>
                            <span class="font-medium text-gray-900">{{
                                message.from_name || message.from_email
                            }}</span>
                            <span class="text-gray-500">
                                &lt;{{ message.from_email }}&gt;
                            </span>
                        </div>
                        <div class="text-gray-400">
                            {{ message.direction }} · {{ message.status }}
                        </div>
                    </div>
                    <div
                        v-if="message.html_body"
                        class="prose max-w-none text-sm text-gray-800"
                        v-html="message.html_body"
                    />
                    <pre
                        v-else
                        class="whitespace-pre-wrap text-sm text-gray-800"
                    >{{ message.text_body }}</pre>
                </article>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
