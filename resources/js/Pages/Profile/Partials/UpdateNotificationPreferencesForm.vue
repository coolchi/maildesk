<script setup>
import { useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { playInboxSound, unlockInboxAudio } from '@/composables/useInboxSound';
import { Volume2 } from '@lucide/vue';

const page = usePage();
const prefs = computed(() => page.props.auth?.user?.preferences ?? {});
const testing = ref(false);

const form = useForm({
    inbox_sound: prefs.value.inbox_sound !== false,
});

const save = () => {
    form.patch(route('profile.preferences'), {
        preserveScroll: true,
    });
};

const testSound = async () => {
    testing.value = true;
    try {
        await unlockInboxAudio();
        await playInboxSound({ force: true });
    } finally {
        window.setTimeout(() => {
            testing.value = false;
        }, 600);
    }
};
</script>

<template>
    <section>
        <header>
            <h2 class="text-lg font-medium text-white">Notifications</h2>
            <p class="mt-1 text-sm text-zinc-400">
                Choose how MailDesk alerts you when new mail arrives.
            </p>
        </header>

        <form class="mt-6 space-y-6" @submit.prevent="save">
            <label
                class="flex cursor-pointer items-start gap-3 rounded-xl border border-zinc-800 bg-zinc-950/50 p-4 transition hover:border-zinc-700"
            >
                <input
                    v-model="form.inbox_sound"
                    type="checkbox"
                    class="mt-1 rounded border-zinc-700 bg-zinc-900 text-cyan-400 focus:ring-cyan-400/40"
                />
                <span>
                    <span class="block text-sm font-medium text-white">
                        Play a sound for new mail
                    </span>
                    <span class="mt-0.5 block text-sm text-zinc-500">
                        A short chime when a message lands in your inbox. On by
                        default.
                    </span>
                </span>
            </label>

            <div class="flex flex-wrap items-center gap-3">
                <PrimaryButton :disabled="form.processing">Save</PrimaryButton>
                <button
                    type="button"
                    class="md-btn-ghost inline-flex items-center gap-1.5"
                    :disabled="testing"
                    @click="testSound"
                >
                    <Volume2 :size="16" />
                    {{ testing ? 'Playing…' : 'Test sound' }}
                </button>
                <Transition
                    enter-active-class="transition ease-in-out"
                    enter-from-class="opacity-0"
                    leave-active-class="transition ease-in-out"
                    leave-to-class="opacity-0"
                >
                    <p
                        v-if="form.recentlySuccessful"
                        class="text-sm text-zinc-400"
                    >
                        Saved.
                    </p>
                </Transition>
            </div>
        </form>
    </section>
</template>
