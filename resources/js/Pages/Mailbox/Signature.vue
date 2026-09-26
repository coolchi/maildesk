<script setup>
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import WysiwygEditor from '@/Components/WysiwygEditor.vue';
import { useToast } from '@/composables/useToast';

const props = defineProps({
    mailbox: {
        type: Object,
        required: true,
    },
});

const toast = useToast();
const signature = ref(props.mailbox.signature || '');
const saving = ref(false);

const save = () => {
    saving.value = true;
    router.put(
        route('mailbox.signature.update'),
        { signature: signature.value },
        {
            preserveScroll: true,
            onSuccess: () => toast.success('Signature saved.'),
            onError: () => toast.error('Could not save signature.'),
            onFinish: () => {
                saving.value = false;
            },
        },
    );
};
</script>

<template>
    <Head title="Signature" />

    <AppLayout>
        <PageHeader
            title="Signature"
            :description="`Added at the bottom of mail you send as ${mailbox.email}.`"
        />

        <div class="mx-auto max-w-2xl">
            <section class="md-card space-y-5 p-5 sm:p-6">
                <div>
                    <div class="text-sm font-medium text-white">
                        {{ mailbox.display_name || mailbox.email }}
                    </div>
                    <div class="mt-0.5 font-mono text-xs text-zinc-500">
                        {{ mailbox.email }}
                    </div>
                </div>

                <div class="overflow-hidden rounded-xl border border-zinc-800">
                    <WysiwygEditor
                        v-model="signature"
                        placeholder="Your name · Role · Contact"
                        min-height="140px"
                    />
                </div>

                <div class="flex justify-end">
                    <button
                        type="button"
                        class="md-btn-solid"
                        :disabled="saving"
                        @click="save"
                    >
                        {{ saving ? 'Saving…' : 'Save signature' }}
                    </button>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
