<script setup>
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import { useComposeModal } from '@/composables/useComposeModal';
import { useToast } from '@/composables/useToast';
import { FilePenLine, PenSquare, Trash2 } from '@lucide/vue';

const props = defineProps({
    drafts: { type: Array, default: () => [] },
});

const { open: openCompose } = useComposeModal();
const toast = useToast();

const openDraft = (draft) => {
    openCompose({
        draft_id: draft.id,
        from: draft.from || undefined,
        to: draft.to || '',
        cc: draft.cc || '',
        bcc: draft.bcc || '',
        subject: draft.subject === '(no subject)' ? '' : draft.subject || '',
        html: draft.html || '<p></p>',
        thread_id: draft.thread_id || null,
    });
};

const destroy = (draft) => {
    if (!window.confirm('Delete this draft?')) {
        return;
    }

    router.delete(route('drafts.destroy', draft.id), {
        preserveScroll: true,
        onSuccess: () => toast.success('Draft deleted.'),
        onError: () => toast.error('Could not delete draft.'),
    });
};
</script>

<template>
    <Head title="Drafts" />

    <AppLayout>
        <PageHeader
            title="Drafts"
            description="Saved messages you can reopen and send later."
        >
            <template #actions>
                <button type="button" class="md-btn-solid" @click="openCompose()">
                    <PenSquare :size="16" />
                    Compose
                </button>
            </template>
        </PageHeader>

        <div v-if="!drafts.length" class="md-card flex flex-col items-center gap-3 px-6 py-16 text-center">
            <FilePenLine :size="28" class="text-zinc-600" />
            <div class="text-sm font-medium text-zinc-300">No drafts yet</div>
            <p class="max-w-sm text-sm text-zinc-500">
                Use Save draft while composing to keep a message here.
            </p>
        </div>

        <div v-else class="md-table-wrap">
            <table class="min-w-full text-left text-sm">
                <thead
                    class="border-b border-zinc-800 text-xs uppercase tracking-wide text-zinc-500"
                >
                    <tr>
                        <th class="px-4 py-3 font-medium">To</th>
                        <th class="px-4 py-3 font-medium">Subject</th>
                        <th class="px-4 py-3 font-medium">Updated</th>
                        <th class="px-4 py-3 text-right font-medium">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-900">
                    <tr
                        v-for="draft in drafts"
                        :key="draft.id"
                        class="cursor-pointer transition hover:bg-zinc-900/50"
                        @click="openDraft(draft)"
                    >
                        <td class="px-4 py-3 text-zinc-300">
                            {{ draft.to || '—' }}
                        </td>
                        <td class="px-4 py-3 text-white">
                            {{ draft.subject }}
                        </td>
                        <td class="px-4 py-3 text-zinc-500">
                            {{ draft.updated }}
                        </td>
                        <td class="px-4 py-3 text-right" @click.stop>
                            <button
                                type="button"
                                class="md-btn-ghost inline-flex items-center gap-1.5"
                                @click="openDraft(draft)"
                            >
                                Open
                            </button>
                            <button
                                type="button"
                                class="md-btn-ghost inline-flex items-center gap-1.5 text-rose-400 hover:text-rose-300"
                                @click="destroy(draft)"
                            >
                                <Trash2 :size="14" />
                                Delete
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>
