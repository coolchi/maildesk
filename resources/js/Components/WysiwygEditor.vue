<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { EditorContent, useEditor } from '@tiptap/vue-3';
import StarterKit from '@tiptap/starter-kit';
import Underline from '@tiptap/extension-underline';
import Link from '@tiptap/extension-link';
import Placeholder from '@tiptap/extension-placeholder';
import Image from '@tiptap/extension-image';
import {
    Bold,
    Italic,
    Underline as UnderlineIcon,
    List,
    ListOrdered,
    Link2,
    Code,
    Undo2,
    Redo2,
    Heading2,
    ImagePlus,
    X,
} from '@lucide/vue';

const model = defineModel({ type: String, default: '' });

const props = defineProps({
    placeholder: { type: String, default: 'Write your email…' },
    minHeight: { type: String, default: '240px' },
    /** email = white canvas for true mail colors */
    variant: {
        type: String,
        default: 'dark',
        validator: (v) => ['dark', 'email'].includes(v),
    },
});

const isEmail = computed(() => props.variant === 'email');

const editor = useEditor({
    content: model.value || '',
    extensions: [
        StarterKit,
        Underline,
        Link.configure({ openOnClick: false }),
        Placeholder.configure({ placeholder: props.placeholder }),
        Image.configure({
            inline: false,
            allowBase64: true,
            HTMLAttributes: {
                class: 'email-inline-image',
            },
        }),
    ],
    editorProps: {
        attributes: {
            class: isEmail.value
                ? 'prose prose-sm max-w-none focus:outline-none px-4 py-3 text-zinc-900 prose-headings:text-zinc-900 prose-a:text-cyan-700'
                : 'prose prose-invert prose-sm max-w-none focus:outline-none px-4 py-3',
            // Fill the editor box so the whole area is clickable; height comes from the minHeight prop.
            style: `min-height: ${props.minHeight}`,
        },
    },
    onUpdate: ({ editor: ed }) => {
        model.value = ed.getHTML();
    },
});

watch(
    () => model.value,
    (value) => {
        if (!editor.value) return;
        const current = editor.value.getHTML();
        if (value !== current && value !== undefined) {
            editor.value.commands.setContent(value || '', { emitUpdate: false });
        }
    },
);

onBeforeUnmount(() => {
    editor.value?.destroy();
});

const linkOpen = ref(false);
const linkUrl = ref('');
const linkInput = ref(null);
const editingLink = ref(false);
const linkFieldId = `editor-link-${Math.random().toString(36).slice(2, 8)}`;

const selectionText = () => {
    const ed = editor.value;
    if (!ed) return '';
    const { from, to } = ed.state.selection;
    if (from === to) return '';
    return ed.state.doc.textBetween(from, to, ' ').trim();
};

const suggestedHref = (previous) => {
    if (previous) return previous;
    const text = selectionText();
    if (!text || /\s/.test(text)) return 'https://';
    if (/^(https?:|mailto:|tel:)/i.test(text)) return text;
    if (/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(text)) return `mailto:${text}`;
    if (/^[\w.-]+\.[a-z]{2,}(\/\S*)?$/i.test(text)) return `https://${text}`;
    return 'https://';
};

const normalizeHref = (raw) => {
    const url = raw.trim();
    if (!url) return '';
    if (/^(https?:|mailto:|tel:|#|\/)/i.test(url)) return url;
    if (/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(url)) return `mailto:${url}`;
    return `https://${url}`;
};

const closeLinkEditor = (restoreFocus = true) => {
    linkOpen.value = false;
    if (restoreFocus) editor.value?.commands.focus();
};

const openLinkEditor = () => {
    if (!editor.value) return;
    if (linkOpen.value) {
        closeLinkEditor();
        return;
    }
    const previous = editor.value.getAttributes('link').href || '';
    editingLink.value = Boolean(previous);
    linkUrl.value = suggestedHref(previous);
    linkOpen.value = true;
    nextTick(() => {
        linkInput.value?.focus();
        linkInput.value?.select();
    });
};

const applyLink = () => {
    const ed = editor.value;
    if (!ed) return;
    const url = normalizeHref(linkUrl.value);
    if (!url) {
        ed.chain().focus().extendMarkRange('link').unsetLink().run();
        linkOpen.value = false;
        return;
    }
    const { from, to } = ed.state.selection;
    if (from === to && !ed.isActive('link')) {
        ed.chain()
            .focus()
            .insertContent({
                type: 'text',
                text: url.replace(/^mailto:/i, ''),
                marks: [{ type: 'link', attrs: { href: url } }],
            })
            .run();
    } else {
        ed.chain().focus().extendMarkRange('link').setLink({ href: url }).run();
    }
    linkOpen.value = false;
};

const removeLink = () => {
    editor.value?.chain().focus().extendMarkRange('link').unsetLink().run();
    linkOpen.value = false;
};

const insertImage = (src, alt = '') => {
    if (!editor.value || !src) return;
    editor.value
        .chain()
        .focus()
        .setImage({ src, alt })
        .run();
};

const onPickImage = (e) => {
    const file = e.target.files?.[0];
    e.target.value = '';
    if (!file || !file.type.startsWith('image/')) return;
    const url = URL.createObjectURL(file);
    insertImage(url, file.name);
};

defineExpose({ insertImage });

const btn = (active) => {
    if (isEmail.value) {
        return active
            ? 'bg-zinc-900 text-white'
            : 'text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900';
    }
    return active
        ? 'bg-cyan-400/20 text-cyan-300'
        : 'text-zinc-400 hover:bg-white/5 hover:text-white';
};

const isReady = computed(() => !!editor.value);
</script>

<template>
    <div
        class="overflow-hidden rounded-xl border"
        :class="
            isEmail
                ? 'border-zinc-300 bg-white shadow-sm'
                : 'border-md-border bg-md-elevated'
        "
    >
        <div
            v-if="isReady"
            class="flex flex-wrap items-center gap-0.5 border-b px-2 py-1.5"
            :class="isEmail ? 'border-zinc-200 bg-zinc-50' : 'border-md-border'"
        >
            <button
                type="button"
                class="rounded p-1.5"
                :class="btn(editor.isActive('bold'))"
                title="Bold"
                @click="editor.chain().focus().toggleBold().run()"
            >
                <Bold :size="15" />
            </button>
            <button
                type="button"
                class="rounded p-1.5"
                :class="btn(editor.isActive('italic'))"
                title="Italic"
                @click="editor.chain().focus().toggleItalic().run()"
            >
                <Italic :size="15" />
            </button>
            <button
                type="button"
                class="rounded p-1.5"
                :class="btn(editor.isActive('underline'))"
                title="Underline"
                @click="editor.chain().focus().toggleUnderline().run()"
            >
                <UnderlineIcon :size="15" />
            </button>
            <button
                type="button"
                class="rounded p-1.5"
                :class="btn(editor.isActive('heading', { level: 2 }))"
                title="Heading"
                @click="
                    editor.chain().focus().toggleHeading({ level: 2 }).run()
                "
            >
                <Heading2 :size="15" />
            </button>
            <span
                class="mx-1 h-4 w-px"
                :class="isEmail ? 'bg-zinc-200' : 'bg-md-border'"
            />
            <button
                type="button"
                class="rounded p-1.5"
                :class="btn(editor.isActive('bulletList'))"
                title="Bullet list"
                @click="editor.chain().focus().toggleBulletList().run()"
            >
                <List :size="15" />
            </button>
            <button
                type="button"
                class="rounded p-1.5"
                :class="btn(editor.isActive('orderedList'))"
                title="Numbered list"
                @click="editor.chain().focus().toggleOrderedList().run()"
            >
                <ListOrdered :size="15" />
            </button>
            <button
                type="button"
                class="rounded p-1.5"
                :class="btn(editor.isActive('link') || linkOpen)"
                title="Link"
                :aria-expanded="linkOpen"
                data-testid="editor-link"
                @click="openLinkEditor"
            >
                <Link2 :size="15" />
            </button>
            <button
                type="button"
                class="rounded p-1.5"
                :class="btn(editor.isActive('codeBlock'))"
                title="Code"
                @click="editor.chain().focus().toggleCodeBlock().run()"
            >
                <Code :size="15" />
            </button>
            <label
                class="cursor-pointer rounded p-1.5"
                :class="btn(false)"
                title="Insert image"
            >
                <ImagePlus :size="15" />
                <input
                    type="file"
                    accept="image/*"
                    class="hidden"
                    @change="onPickImage"
                />
            </label>
            <span
                class="mx-1 h-4 w-px"
                :class="isEmail ? 'bg-zinc-200' : 'bg-md-border'"
            />
            <button
                type="button"
                class="rounded p-1.5"
                :class="btn(false)"
                title="Undo"
                @click="editor.chain().focus().undo().run()"
            >
                <Undo2 :size="15" />
            </button>
            <button
                type="button"
                class="rounded p-1.5"
                :class="btn(false)"
                title="Redo"
                @click="editor.chain().focus().redo().run()"
            >
                <Redo2 :size="15" />
            </button>
        </div>
        <form
            v-if="linkOpen"
            class="flex flex-wrap items-center gap-2 border-b px-2 py-2"
            :class="
                isEmail
                    ? 'border-zinc-200 bg-zinc-50'
                    : 'border-md-border bg-black/20'
            "
            data-testid="editor-link-bar"
            @submit.prevent="applyLink"
        >
            <label
                class="sr-only"
                :for="linkFieldId"
            >Link address</label>
            <input
                :id="linkFieldId"
                ref="linkInput"
                v-model="linkUrl"
                type="text"
                inputmode="url"
                autocomplete="off"
                spellcheck="false"
                class="min-w-[12rem] flex-1 rounded-lg border px-2.5 py-1.5 text-sm focus:outline-none focus:ring-1"
                :class="
                    isEmail
                        ? 'border-zinc-300 bg-white text-zinc-900 placeholder:text-zinc-400 focus:border-cyan-600 focus:ring-cyan-600/30'
                        : 'border-zinc-700 bg-zinc-950 text-zinc-100 placeholder:text-zinc-500 focus:border-cyan-400/60 focus:ring-cyan-400/40'
                "
                placeholder="https://example.com or name@domain.com"
                data-testid="editor-link-url"
                @keydown.esc.prevent="closeLinkEditor()"
            />
            <button
                type="submit"
                class="rounded-lg px-2.5 py-1.5 text-xs font-medium"
                :class="
                    isEmail
                        ? 'bg-zinc-900 text-white hover:bg-zinc-700'
                        : 'bg-cyan-400 text-zinc-950 hover:bg-cyan-300'
                "
                data-testid="editor-link-apply"
            >
                Apply
            </button>
            <button
                v-if="editingLink"
                type="button"
                class="rounded-lg px-2 py-1.5 text-xs font-medium"
                :class="
                    isEmail
                        ? 'text-rose-600 hover:bg-rose-50'
                        : 'text-rose-300 hover:bg-rose-500/10'
                "
                data-testid="editor-link-remove"
                @click="removeLink"
            >
                Remove
            </button>
            <button
                type="button"
                class="rounded p-1.5"
                :class="
                    isEmail
                        ? 'text-zinc-500 hover:bg-zinc-200 hover:text-zinc-900'
                        : 'text-zinc-400 hover:bg-white/5 hover:text-white'
                "
                title="Cancel"
                data-testid="editor-link-cancel"
                @click="closeLinkEditor()"
            >
                <X :size="14" />
            </button>
        </form>
        <div
            :class="isEmail ? 'bg-white' : ''"
            :style="{ minHeight }"
        >
            <EditorContent :editor="editor" />
        </div>
    </div>
</template>

<style>
.tiptap p.is-editor-empty:first-child::before {
    color: #a1a1aa;
    content: attr(data-placeholder);
    float: left;
    height: 0;
    pointer-events: none;
}
.tiptap a {
    text-decoration: underline;
}
.tiptap img.email-inline-image,
.tiptap img {
    max-width: 100%;
    height: auto;
    border-radius: 8px;
    margin: 0.5rem 0;
}
</style>
