<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import DeleteUserForm from './Partials/DeleteUserForm.vue';
import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm.vue';
import UpdateNotificationPreferencesForm from './Partials/UpdateNotificationPreferencesForm.vue';
import UpdateSignatureForm from './Partials/UpdateSignatureForm.vue';
import PageHeader from '@/Components/PageHeader.vue';
import { Head } from '@inertiajs/vue3';

defineProps({
    mustVerifyEmail: {
        type: Boolean,
    },
    status: {
        type: String,
    },
    mailbox: {
        type: Object,
        default: null,
    },
});
</script>

<template>
    <Head title="Profile" />

    <AppLayout>
        <PageHeader
            title="Profile"
            description="Manage your account details and security."
        />

        <div class="mx-auto max-w-3xl space-y-4">
            <div class="md-card p-6">
                <UpdateProfileInformationForm
                    :must-verify-email="mustVerifyEmail"
                    :status="status"
                    class="max-w-xl"
                />
            </div>

            <div class="md-card p-6">
                <UpdateNotificationPreferencesForm class="max-w-xl" />
            </div>

            <div v-if="mailbox" class="md-card p-6" data-testid="profile-signature">
                <UpdateSignatureForm :mailbox="mailbox" />
            </div>

            <div class="md-card p-6">
                <UpdatePasswordForm class="max-w-xl" />
            </div>

            <div class="md-card p-6">
                <DeleteUserForm class="max-w-xl" />
            </div>
        </div>
    </AppLayout>
</template>
