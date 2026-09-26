<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';

const props = defineProps({
    settings: { type: Object, required: true },
    providers: { type: Array, default: () => [] },
});

const form = useForm({
    signup_open: !!props.settings.signup_open,
    platform_name: props.settings.platform_name || '',
    support_email: props.settings.support_email || '',
    default_workspace_provider_id: props.settings.default_workspace_provider_id ?? null,
});

const save = () => form.put(route('admin.settings.update'), { preserveScroll: true });
</script>

<template>
    <Head title="Admin · Settings" />

    <AdminLayout>
        <PageHeader
            title="Platform settings"
            description="Settings the application reads at runtime. Leave text fields empty to use the environment defaults."
        />

        <form class="md-card max-w-2xl space-y-6 p-6" @submit.prevent="save">
            <label class="flex items-start gap-3">
                <input v-model="form.signup_open" type="checkbox" class="mt-1 rounded border-zinc-700 bg-zinc-900" />
                <span>
                    <span class="block text-sm font-medium text-white">Allow new sign-ups</span>
                    <span class="block text-xs text-zinc-500">When off, /register redirects to login and the landing page hides the sign-up button. Existing users are unaffected.</span>
                </span>
            </label>

            <div>
                <label class="block text-sm font-medium text-white" for="platform_name">Platform name</label>
                <input id="platform_name" v-model="form.platform_name" type="text" maxlength="80" class="md-input mt-1 w-full" placeholder="Uses APP_NAME when empty" />
                <p class="mt-1 text-xs text-zinc-500">Overrides the server-side app name (page title fallback, notifications).</p>
                <p v-if="form.errors.platform_name" class="mt-1 text-xs text-red-400">{{ form.errors.platform_name }}</p>
            </div>

            <div>
                <label class="block text-sm font-medium text-white" for="support_email">Support email</label>
                <input id="support_email" v-model="form.support_email" type="email" class="md-input mt-1 w-full" placeholder="Shown on the Help page" />
                <p v-if="form.errors.support_email" class="mt-1 text-xs text-red-400">{{ form.errors.support_email }}</p>
            </div>

            <div>
                <label class="block text-sm font-medium text-white" for="default_provider">Mail provider for new workspaces</label>
                <select id="default_provider" v-model="form.default_workspace_provider_id" class="md-input mt-1 w-full">
                    <option :value="null">None (current behaviour)</option>
                    <option v-for="p in providers" :key="p.id" :value="p.id">
                        {{ p.name }} ({{ p.driver }}){{ p.isDefault ? ' · platform default' : '' }}
                    </option>
                </select>
                <p v-if="form.errors.default_workspace_provider_id" class="mt-1 text-xs text-red-400">{{ form.errors.default_workspace_provider_id }}</p>
            </div>

            <div class="flex justify-end">
                <button type="submit" class="md-btn-solid" :disabled="form.processing">Save settings</button>
            </div>
        </form>
    </AdminLayout>
</template>
