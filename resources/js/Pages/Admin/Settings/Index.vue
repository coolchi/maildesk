<script setup>
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';

const props = defineProps({
    settings: { type: Object, required: true },
    providers: { type: Array, default: () => [] },
    aiProviders: { type: Array, default: () => [] },
});

const aiFeatureEntries = computed(() =>
    Object.values(props.settings.ai?.features || {}),
);

const aiFeaturesForm = () =>
    Object.fromEntries(
        aiFeatureEntries.value.map((feature) => [feature.key, !!feature.enabled]),
    );

const selectedProviderMeta = computed(() =>
    props.aiProviders.find((p) => p.key === form.ai_provider) || null,
);

const form = useForm({
    signup_open: !!props.settings.signup_open,
    platform_name: props.settings.platform_name || '',
    support_email: props.settings.support_email || '',
    default_workspace_provider_id: props.settings.default_workspace_provider_id ?? null,
    trial_days: props.settings.trial_days ?? 14,
    ai_enabled: !!props.settings.ai?.enabled,
    ai_features: aiFeaturesForm(),
    ai_provider: props.settings.ai?.provider || 'openai',
    ai_model: props.settings.ai?.model || '',
    ai_api_key: '',
    ai_clear_api_key: false,
});

const toggleAllAiFeatures = (enabled) => {
    form.ai_enabled = enabled;
    Object.keys(form.ai_features).forEach((key) => {
        form.ai_features[key] = enabled;
    });
};

const save = () => form.put(route('admin.settings.update'), { preserveScroll: true });
</script>

<template>
    <Head title="Admin · Settings" />

    <AdminLayout>
        <PageHeader
            title="Platform settings"
            description="Settings the application reads at runtime. Leave text fields empty to use the environment defaults."
        />

        <form class="md-card max-w-2xl space-y-8 p-6" @submit.prevent="save">
            <section class="space-y-6">
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

                <div>
                    <label class="block text-sm font-medium text-white" for="trial_days">Free trial period (days)</label>
                    <input
                        id="trial_days"
                        v-model.number="form.trial_days"
                        type="number"
                        min="1"
                        max="365"
                        class="md-input mt-1 w-full max-w-[12rem]"
                    />
                    <p class="mt-1 text-xs text-zinc-500">
                        New workspaces get a trial of this length with full access. There is no free tier —
                        when the trial ends without payment, the workspace is limited to Settings and Billing.
                        Changing this value does not rewrite existing trials.
                    </p>
                    <p v-if="form.errors.trial_days" class="mt-1 text-xs text-red-400">{{ form.errors.trial_days }}</p>
                </div>
            </section>

            <section class="space-y-4 border-t border-zinc-800 pt-6">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h3 class="text-sm font-semibold text-white">AI features</h3>
                        <p class="mt-1 text-xs text-zinc-500">
                            Platform-wide switches. Individual features only run when the master switch is on.
                            Defaults are off until you enable them.
                        </p>
                    </div>
                    <div class="flex gap-2">
                        <button type="button" class="md-btn text-xs" @click="toggleAllAiFeatures(true)">
                            Enable all
                        </button>
                        <button type="button" class="md-btn text-xs" @click="toggleAllAiFeatures(false)">
                            Disable all
                        </button>
                    </div>
                </div>

                <label class="flex items-start gap-3 rounded-lg border border-zinc-800 bg-zinc-950/40 p-3">
                    <input v-model="form.ai_enabled" type="checkbox" class="mt-1 rounded border-zinc-700 bg-zinc-900" />
                    <span>
                        <span class="block text-sm font-medium text-white">Enable AI features</span>
                        <span class="block text-xs text-zinc-500">
                            Master switch. When off, every AI feature below is treated as disabled even if its own toggle is on.
                        </span>
                    </span>
                </label>
                <p v-if="form.errors.ai_enabled" class="text-xs text-red-400">{{ form.errors.ai_enabled }}</p>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="block text-sm font-medium text-white" for="ai_provider">AI provider</label>
                        <select id="ai_provider" v-model="form.ai_provider" class="md-input mt-1 w-full">
                            <option v-for="provider in aiProviders" :key="provider.key" :value="provider.key">
                                {{ provider.label }}
                            </option>
                        </select>
                        <p v-if="form.errors.ai_provider" class="mt-1 text-xs text-red-400">{{ form.errors.ai_provider }}</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-white" for="ai_model">Model</label>
                        <input
                            id="ai_model"
                            v-model="form.ai_model"
                            type="text"
                            maxlength="120"
                            class="md-input mt-1 w-full"
                            :placeholder="selectedProviderMeta?.default_model || 'Provider default'"
                        />
                        <p class="mt-1 text-xs text-zinc-500">Leave empty to use the provider default.</p>
                        <p v-if="form.errors.ai_model" class="mt-1 text-xs text-red-400">{{ form.errors.ai_model }}</p>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-white" for="ai_api_key">API key</label>
                    <input
                        id="ai_api_key"
                        v-model="form.ai_api_key"
                        type="password"
                        autocomplete="new-password"
                        class="md-input mt-1 w-full"
                        :placeholder="settings.ai?.api_key_set ? '•••••••• (saved — leave blank to keep)' : 'Uses OPENAI_API_KEY / ANTHROPIC_API_KEY when empty'"
                    />
                    <div class="mt-2 flex flex-wrap items-center gap-3">
                        <label v-if="settings.ai?.api_key_set" class="flex items-center gap-2 text-xs text-zinc-400">
                            <input v-model="form.ai_clear_api_key" type="checkbox" class="rounded border-zinc-700 bg-zinc-900" />
                            Clear saved API key
                        </label>
                        <p class="text-xs text-zinc-500">Stored encrypted. Never shown after save.</p>
                    </div>
                    <p v-if="form.errors.ai_api_key" class="mt-1 text-xs text-red-400">{{ form.errors.ai_api_key }}</p>
                </div>

                <div class="space-y-3" :class="{ 'opacity-50': !form.ai_enabled }">
                    <label
                        v-for="feature in aiFeatureEntries"
                        :key="feature.key"
                        class="flex items-start gap-3"
                    >
                        <input
                            v-model="form.ai_features[feature.key]"
                            type="checkbox"
                            class="mt-1 rounded border-zinc-700 bg-zinc-900"
                            :disabled="!form.ai_enabled"
                        />
                        <span>
                            <span class="block text-sm font-medium text-white">{{ feature.label }}</span>
                            <span class="block text-xs text-zinc-500">{{ feature.description }}</span>
                        </span>
                    </label>
                </div>
                <p v-if="form.errors.ai_features" class="text-xs text-red-400">{{ form.errors.ai_features }}</p>
            </section>

            <div class="flex justify-end border-t border-zinc-800 pt-6">
                <button type="submit" class="md-btn-solid" :disabled="form.processing">Save settings</button>
            </div>
        </form>
    </AdminLayout>
</template>
