<script setup>
import { EditorContent, useEditor } from '@tiptap/vue-3';
import StarterKit from '@tiptap/starter-kit';
import Link from '@tiptap/extension-link';
import { onBeforeUnmount, ref, watch } from 'vue';

const props = defineProps({ modelValue: String, format: String });
const emit = defineEmits(['update:modelValue', 'formatted']);
const linkError = ref('');
const linkOpen = ref(false);
const linkValue = ref('');
const asHtml = (value, format) => format === 'html' ? value : `<p>${(value || '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('\n', '<br>')}</p>`;
const editor = useEditor({
    content: asHtml(props.modelValue, props.format),
    extensions: [StarterKit.configure({ link: false, heading: { levels: [1, 2, 3] } }), Link.configure({ openOnClick: false })],
    editorProps: { attributes: { class: 'notification-message min-h-40 rounded-b-md border border-slate-300 bg-white p-3 text-sm', role: 'textbox', 'aria-label': 'Message', 'aria-multiline': 'true' } },
    onUpdate: ({ editor }) => { emit('update:modelValue', editor.getHTML()); emit('formatted'); },
});
watch(() => [props.modelValue, props.format], ([value, format]) => {
    const html = asHtml(value, format);
    if (editor.value && editor.value.getHTML() !== html) editor.value.commands.setContent(html, { emitUpdate: false });
});
const openLink = () => {
    linkValue.value = editor.value.getAttributes('link').href || '';
    linkError.value = '';
    linkOpen.value = !linkOpen.value;
};
const setLink = () => {
    linkError.value = '';
    if (!linkValue.value.trim()) { editor.value.chain().focus().extendMarkRange('link').unsetLink().run(); linkOpen.value = false; return; }
    try {
        const url = new URL(linkValue.value.trim());
        if (!['http:', 'https:', 'mailto:'].includes(url.protocol)) throw new Error('Unsupported link');
        editor.value.chain().focus().extendMarkRange('link').setLink({ href: url.href }).run();
        linkOpen.value = false;
    } catch {
        linkError.value = 'Enter a valid http, https or mailto link.';
    }
};
onBeforeUnmount(() => editor.value?.destroy());
</script>

<template>
    <div>
        <p class="text-sm font-bold text-slate-700">Message</p>
        <div v-if="editor" role="toolbar" aria-label="Message formatting" class="mt-2 flex flex-wrap gap-1 rounded-t-md border border-b-0 border-slate-300 bg-slate-50 p-2">
            <button type="button" class="rounded px-2 py-1 font-bold" :class="{ 'bg-cyan-100': editor.isActive('bold') }" :aria-pressed="editor.isActive('bold')" @click="editor.chain().focus().toggleBold().run()">Bold</button>
            <button type="button" class="rounded px-2 py-1 italic" :class="{ 'bg-cyan-100': editor.isActive('italic') }" :aria-pressed="editor.isActive('italic')" @click="editor.chain().focus().toggleItalic().run()">Italic</button>
            <button type="button" class="rounded px-2 py-1" :class="{ 'bg-cyan-100': editor.isActive('heading', { level: 2 }) }" :aria-pressed="editor.isActive('heading', { level: 2 })" @click="editor.chain().focus().toggleHeading({ level: 2 }).run()">Heading</button>
            <button type="button" class="rounded px-2 py-1" :class="{ 'bg-cyan-100': editor.isActive('bulletList') }" :aria-pressed="editor.isActive('bulletList')" @click="editor.chain().focus().toggleBulletList().run()">Bullets</button>
            <button type="button" class="rounded px-2 py-1" :class="{ 'bg-cyan-100': editor.isActive('orderedList') }" :aria-pressed="editor.isActive('orderedList')" @click="editor.chain().focus().toggleOrderedList().run()">Numbered</button>
            <button type="button" class="rounded px-2 py-1 underline" :aria-expanded="linkOpen" @click="openLink">Link</button>
        </div>
        <EditorContent :editor="editor" />
        <div v-if="linkOpen" class="mt-2 flex flex-wrap items-end gap-2">
            <label class="min-w-52 flex-1">Link URL<input v-model="linkValue" placeholder="https://example.com" @keydown.enter.prevent="setLink" /></label>
            <button type="button" class="rounded-md border border-slate-300 px-3 py-2 text-sm font-semibold" @click="setLink">Apply link</button>
            <button type="button" class="rounded-md border border-slate-300 px-3 py-2 text-sm font-semibold" @click="linkOpen = false">Cancel</button>
        </div>
        <p v-if="linkOpen" class="mt-1 text-xs text-slate-500">Leave the URL empty to remove a link.</p>
        <p v-if="linkError" role="alert" class="mt-2 text-sm text-red-700">{{ linkError }}</p>
    </div>
</template>
