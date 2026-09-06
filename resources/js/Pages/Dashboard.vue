<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    stats: Object,
    recentMessages: Array,
    organization: Object,
});
</script>

<template>
    <Head title="Dashboard" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                MailDesk
            </h2>
        </template>

        <div class="py-10">
            <div class="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div
                        v-for="card in [
                            { label: 'Sent', value: stats.sent },
                            { label: 'Received', value: stats.received },
                            { label: 'Domains', value: stats.domains },
                            { label: 'API Keys', value: stats.api_keys },
                        ]"
                        :key="card.label"
                        class="bg-white p-5 shadow-sm sm:rounded-lg"
                    >
                        <div class="text-sm text-gray-500">{{ card.label }}</div>
                        <div class="mt-1 text-3xl font-semibold text-gray-900">
                            {{ card.value }}
                        </div>
                    </div>
                </div>

                <div class="bg-white p-6 shadow-sm sm:rounded-lg">
                    <div class="mb-4 flex items-center justify-between">
                        <h3 class="text-lg font-medium text-gray-900">
                            Recent activity
                        </h3>
                        <Link
                            :href="route('compose')"
                            class="rounded-md bg-gray-900 px-3 py-2 text-sm font-medium text-white"
                        >
                            Compose
                        </Link>
                    </div>

                    <div
                        v-if="!recentMessages.length"
                        class="text-sm text-gray-500"
                    >
                        No messages yet. Create an API key or compose from the
                        dashboard.
                    </div>

                    <ul v-else class="divide-y divide-gray-100">
                        <li
                            v-for="message in recentMessages"
                            :key="message.id"
                            class="flex items-center justify-between py-3 text-sm"
                        >
                            <div>
                                <div class="font-medium text-gray-900">
                                    {{ message.subject }}
                                </div>
                                <div class="text-gray-500">
                                    {{ message.direction }} ·
                                    {{ message.from_email }} ·
                                    {{ message.status }}
                                </div>
                            </div>
                            <div class="text-gray-400">
                                {{ message.created_at }}
                            </div>
                        </li>
                    </ul>
                </div>

                <div
                    v-if="organization"
                    class="bg-white p-6 text-sm text-gray-600 shadow-sm sm:rounded-lg"
                >
                    Workspace:
                    <span class="font-medium text-gray-900">{{
                        organization.name
                    }}</span>
                    · Provider:
                    <span class="font-medium text-gray-900">{{
                        organization.default_provider
                    }}</span>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
