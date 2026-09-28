<script setup>
import WysiwygEditor from '@/Components/WysiwygEditor.vue';
import { useToast } from '@/composables/useToast';
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

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
    <section>
        <header>
            <h2 class="text-lg font-medium text-white">Signature</h2>
            <p class="mt-1 text-sm text-zinc-400">
                Added at the bottom of mail you send as {{ mailbox.email }}.
            </p>
        </header>

        <div class="mt-6 space-y-4">
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
                    data-testid="save-signature"
                    :disabled="saving"
                    @click="save"
                >
                    {{ saving ? 'Saving…' : 'Save signature' }}
                </button>
            </div>
        </div>
    </section>
</template>
