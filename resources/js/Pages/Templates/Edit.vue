<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import WysiwygEditor from '@/Components/WysiwygEditor.vue';
import DesignPicker from '@/Components/DesignPicker.vue';
import DesignFrame from '@/Components/DesignFrame.vue';
import EmailFrame from '@/Components/EmailFrame.vue';
import { useDesigns } from '@/composables/useDesigns';
import RowActions from '@/Components/RowActions.vue';
import { useToast } from '@/composables/useToast';
import {
    ArrowLeft,
    Braces,
    Code2,
    Copy,
    Eye,
    Monitor,
    Pencil,
    Save,
    Send,
    Smartphone,
} from '@lucide/vue';

const props = defineProps({
    id: { type: [String, Number], required: true },
    template: { type: Object, required: true },
});

const page = usePage();
const toast = useToast();
const showTest = ref(false);
const testTo = ref('');
const sendingTest = ref(false);
const { apply, defaultKey } = useDesigns();

const name = ref(props.template.name || '');
const subject = ref(props.template.subject || '');
const status = ref(props.template.status || 'published');
const html = ref(props.template.html || '');
const designKey = ref(props.template.design_key || '');
const frameDesign = computed(() => designKey.value || defaultKey.value || '');
const mode = ref('design'); // design | code
const device = ref('desktop');
const dirty = ref(false);
const showPreview = ref(true);

watch(
    () => props.template,
    (tpl) => {
        name.value = tpl.name || '';
        subject.value = tpl.subject || '';
        status.value = tpl.status || 'published';
        html.value = tpl.html || '';
        designKey.value = tpl.design_key || '';
        dirty.value = false;
    },
);

watch([name, subject, html, designKey], () => {
    dirty.value = true;
});

const variables = [
    { key: 'first_name', sample: 'Maya' },
    { key: 'email', sample: 'maya@studio.co' },
    { key: 'reset_url', sample: 'https://maildesk.test/reset/abc' },
    { key: 'dashboard_url', sample: 'https://maildesk.test/emails' },
    { key: 'expires_in', sample: '60 minutes' },
    { key: 'invoice_id', sample: '1042' },
    { key: 'amount', sample: '$49.00' },
    { key: 'paid_at', sample: 'Sep 6, 2026' },
];

const previewHtml = computed(() => {
    let body = html.value || '';
    for (const v of variables) {
        body = body.replaceAll(`{{${v.key}}}`, v.sample);
    }
    return apply(body, frameDesign.value);
});

const previewSubject = computed(() => {
    let out = subject.value || '(no subject)';
    for (const v of variables) {
        out = out.replaceAll(`{{${v.key}}}`, v.sample);
    }
    return out;
});

const insertVariable = async (key) => {
    const token = `{{${key}}}`;
    if (mode.value === 'code') {
        html.value += (html.value.endsWith(' ') ? '' : ' ') + token;
        dirty.value = true;
        toast.info(`Inserted {{${key}}}`);
        return;
    }
    html.value = html.value.includes('</p>')
        ? html.value.replace(/<\/p>(?![\s\S]*<\/p>)/, ` ${token}</p>`)
        : html.value + token;
    dirty.value = true;
    toast.info(`Inserted {{${key}}}`);
    await nextTick();
};

const copyHtml = async () => {
    try {
        await navigator.clipboard.writeText(html.value);
        toast.success('HTML copied.');
    } catch {
        toast.error('Copy failed.');
    }
};

const save = () => {
    router.put(
        route('templates.update', props.id),
        {
            name: name.value,
            subject: subject.value,
            html: html.value,
            design_key: designKey.value || null,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                dirty.value = false;
                toast.success('Template saved.');
            },
            onError: () => toast.error('Could not save template.'),
        },
    );
};

const openTest = () => {
    testTo.value = page.props.auth?.user?.email || '';
    showTest.value = true;
};

const sendTest = () => {
    const to = testTo.value.trim();
    if (!to || sendingTest.value) return;
    sendingTest.value = true;
    router.post(route('templates.test', props.id), { to }, {
        preserveScroll: true,
        onSuccess: (visit) => {
            const message = visit.props.flash?.success;
            const error = visit.props.flash?.error;
            if (error) {
                toast.error(error);
                return;
            }
            showTest.value = false;
            if (message) toast.success(message);
        },
        onError: (errors) => {
            const message = Array.isArray(errors.to) ? errors.to[0] : errors.to;
            toast.error(message || 'Could not send the test email.');
        },
        onFinish: () => {
            sendingTest.value = false;
        },
    });
};

const publish = () => {
    router.post(route('templates.publish', props.id), {}, {
        preserveScroll: true,
        onSuccess: (page) => {
            const message = page.props.flash?.success;
            const error = page.props.flash?.error;
            if (error) {
                toast.error(error);
            } else if (message) {
                toast.success(message);
            }
        },
        onError: () => toast.error('Could not update the template.'),
    });
};

const moreActions = computed(() => [
    { id: 'copy', label: 'Copy HTML', icon: Copy },
    { id: 'test', label: 'Send test email', icon: Send },
    {
        id: 'publish',
        label: status.value === 'published' ? 'Unpublish' : 'Publish',
        icon: Eye,
    },
]);

const onMore = (item) => {
    if (item.id === 'copy') copyHtml();
    else if (item.id === 'test') openTest();
    else if (item.id === 'publish') publish();
};
</script>

<template>
    <Head :title="`Edit · ${name}`" />

    <AppLayout>
        <!-- Compact header -->
        <div
            class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
        >
            <div class="min-w-0">
                <Link
                    :href="route('templates')"
                    class="mb-2 inline-flex items-center gap-1.5 text-sm text-zinc-500 transition hover:text-cyan-300"
                >
                    <ArrowLeft :size="14" />
                    Templates
                </Link>
                <div class="flex flex-wrap items-center gap-2">
                    <input
                        v-model="name"
                        class="min-w-0 flex-1 bg-transparent text-xl font-semibold tracking-tight text-white outline-none placeholder:text-zinc-600 sm:flex-none sm:text-2xl"
                        placeholder="Template name"
                    />
                    <StatusBadge :status="status" />
                    <span
                        v-if="dirty"
                        class="rounded-full bg-amber-500/15 px-2 py-0.5 text-[11px] text-amber-300"
                    >
                        Unsaved
                    </span>
                </div>
            </div>
            <div class="flex shrink-0 flex-wrap items-center gap-2">
                <RowActions :items="moreActions" @select="onMore" />
                <button type="button" class="md-btn-ghost" @click="openTest">
                    <Send :size="15" />
                    Test
                </button>
                <button type="button" class="md-btn-solid" @click="save">
                    <Save :size="15" />
                    Save
                </button>
            </div>
        </div>

        <!-- Subject -->
        <div class="mb-4 grid gap-4 sm:grid-cols-[minmax(0,1fr)_220px]">
            <div>
                <label class="mb-1.5 block text-xs text-zinc-500">Subject</label>
                <input
                    v-model="subject"
                    class="md-input"
                    placeholder="Email subject line…"
                />
            </div>
            <DesignPicker
                v-model="designKey"
                :plain-label="defaultKey ? 'Workspace default' : 'Plain mail'"
            />
        </div>

        <!-- Variables strip -->
        <div
            class="mb-4 flex flex-wrap items-center gap-2 rounded-xl border border-zinc-800 bg-zinc-950/60 px-3 py-2.5"
        >
            <span
                class="inline-flex items-center gap-1.5 text-[11px] font-medium uppercase tracking-wide text-zinc-500"
            >
                <Braces :size="12" class="text-cyan-300" />
                Insert
            </span>
            <button
                v-for="v in variables"
                :key="v.key"
                type="button"
                class="rounded-md border border-zinc-800 bg-black/40 px-2 py-1 font-mono text-[11px] text-cyan-300 transition hover:border-cyan-400/40 hover:bg-cyan-400/10"
                @click="insertVariable(v.key)"
            >
                {{ '{' + '{' + v.key + '}' + '}' }}
            </button>
        </div>

        <!-- Split workspace -->
        <div
            class="grid gap-5"
            :class="
                showPreview
                    ? 'xl:grid-cols-[minmax(0,1.1fr)_minmax(0,0.9fr)]'
                    : 'grid-cols-1'
            "
        >
            <!-- Editor panel -->
            <section
                class="flex min-h-0 flex-col overflow-hidden rounded-2xl border border-zinc-800 bg-zinc-950"
            >
                <div
                    class="flex flex-wrap items-center justify-between gap-2 border-b border-zinc-800 px-3 py-2"
                >
                    <div
                        class="inline-flex rounded-full border border-zinc-800 bg-black p-0.5"
                    >
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-medium transition sm:text-sm"
                            :class="
                                mode === 'design'
                                    ? 'bg-zinc-800 text-white'
                                    : 'text-zinc-500 hover:text-zinc-300'
                            "
                            @click="mode = 'design'"
                        >
                            <Pencil :size="13" />
                            Design
                        </button>
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-medium transition sm:text-sm"
                            :class="
                                mode === 'code'
                                    ? 'bg-zinc-800 text-white'
                                    : 'text-zinc-500 hover:text-zinc-300'
                            "
                            @click="mode = 'code'"
                        >
                            <Code2 :size="13" />
                            HTML
                        </button>
                    </div>
                    <div class="flex items-center gap-1">
                        <button
                            type="button"
                            class="md-btn-ghost !px-2.5 !py-1.5 text-xs"
                            title="Toggle preview"
                            @click="showPreview = !showPreview"
                        >
                            <Eye :size="14" />
                            <span class="hidden sm:inline">{{
                                showPreview ? 'Hide preview' : 'Show preview'
                            }}</span>
                        </button>
                        <button
                            type="button"
                            class="md-btn-ghost !px-2.5 !py-1.5 text-xs"
                            @click="copyHtml"
                        >
                            <Copy :size="14" />
                            <span class="hidden sm:inline">Copy</span>
                        </button>
                    </div>
                </div>

                <div class="min-h-[480px] flex-1 p-3 sm:p-4">
                    <DesignFrame v-if="mode === 'design'" :design="frameDesign">
                        <WysiwygEditor
                            v-model="html"
                            variant="email"
                            min-height="440px"
                            placeholder="Design your email…"
                        />
                    </DesignFrame>
                    <textarea
                        v-else
                        v-model="html"
                        class="h-full min-h-[440px] w-full resize-y rounded-xl border border-zinc-200 bg-white p-4 font-mono text-[12px] leading-relaxed text-zinc-800 outline-none focus:border-cyan-500/50 focus:ring-1 focus:ring-cyan-500/30"
                        spellcheck="false"
                    />
                </div>
            </section>

            <!-- Preview panel -->
            <section
                v-if="showPreview"
                class="flex min-h-0 min-w-0 flex-col overflow-hidden rounded-2xl border border-zinc-800 bg-zinc-950"
            >
                <div
                    class="flex items-center justify-between gap-2 border-b border-zinc-800 px-3 py-2"
                >
                    <span class="text-xs font-medium text-zinc-400"
                        >Live preview</span
                    >
                    <div
                        class="inline-flex rounded-full border border-zinc-800 bg-black p-0.5"
                    >
                        <button
                            type="button"
                            class="rounded-full p-1.5 transition"
                            :class="
                                device === 'desktop'
                                    ? 'bg-zinc-800 text-white'
                                    : 'text-zinc-500 hover:text-zinc-300'
                            "
                            title="Desktop"
                            @click="device = 'desktop'"
                        >
                            <Monitor :size="14" />
                        </button>
                        <button
                            type="button"
                            class="rounded-full p-1.5 transition"
                            :class="
                                device === 'mobile'
                                    ? 'bg-zinc-800 text-white'
                                    : 'text-zinc-500 hover:text-zinc-300'
                            "
                            title="Mobile"
                            @click="device = 'mobile'"
                        >
                            <Smartphone :size="14" />
                        </button>
                    </div>
                </div>

                <div class="flex-1 overflow-auto bg-zinc-900/40 p-4 sm:p-5">
                    <div
                        class="mx-auto transition-all duration-300"
                        :class="
                            device === 'mobile'
                                ? 'max-w-[360px]'
                                : 'max-w-[560px]'
                        "
                    >
                        <div
                            class="overflow-hidden rounded-xl border border-zinc-700/80 bg-white shadow-xl"
                        >
                            <div
                                class="space-y-1.5 border-b border-zinc-200 bg-zinc-50 px-4 py-3"
                            >
                                <div class="flex gap-2 text-xs">
                                    <span class="w-12 shrink-0 text-zinc-400"
                                        >From</span
                                    >
                                    <span class="text-zinc-700"
                                        >Acme &lt;hello@acme.com&gt;</span
                                    >
                                </div>
                                <div class="flex gap-2 text-xs">
                                    <span class="w-12 shrink-0 text-zinc-400"
                                        >To</span
                                    >
                                    <span class="text-zinc-600"
                                        >maya@studio.co</span
                                    >
                                </div>
                                <div class="flex gap-2 text-xs">
                                    <span class="w-12 shrink-0 text-zinc-400"
                                        >Subject</span
                                    >
                                    <span class="font-medium text-zinc-900">{{
                                        previewSubject
                                    }}</span>
                                </div>
                            </div>
                            <EmailFrame
                                :key="device"
                                :html="previewHtml"
                                :collapse-quotes="false"
                                :min-height="160"
                                title="Email preview"
                            />
                        </div>
                        <p
                            class="mt-3 text-center text-[11px] text-zinc-600"
                        >
                            Variables shown with sample data
                        </p>
                    </div>
                </div>
            </section>
        </div>

        <Modal
            :show="showTest"
            title="Send a test"
            description="Choose who should receive this template."
            max-width="sm"
            @close="showTest = false"
        >
            <form @submit.prevent="sendTest">
                <label class="mb-1.5 block text-xs text-zinc-500" for="template-test-to">To</label>
                <input
                    id="template-test-to"
                    v-model="testTo"
                    type="email"
                    required
                    class="md-input"
                    placeholder="name@company.com"
                    autocomplete="email"
                />
            </form>
            <template #footer>
                <button type="button" class="md-btn-ghost" @click="showTest = false">
                    Cancel
                </button>
                <button
                    type="button"
                    class="md-btn-solid"
                    :disabled="sendingTest || !testTo.trim()"
                    @click="sendTest"
                >
                    {{ sendingTest ? 'Sending…' : 'Send test' }}
                </button>
            </template>
        </Modal>
    </AppLayout>
</template>
