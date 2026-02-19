<template>
    <inbox-stack :open="open" @closed="$emit('close')" title="ActivityPub JSON" inset>
        <pre class="ap-json-viewer" v-html="highlightJson(content)"></pre>
        <template #footer-end>
            <button @click="$emit('close')" class="relative inline-flex items-center justify-center whitespace-nowrap shrink-0 font-medium antialiased cursor-pointer no-underline disabled:[&_svg]:opacity-30 disabled:cursor-not-allowed [&_svg]:shrink-0 dark:[&_svg]:text-white bg-white hover:bg-gray-50 text-gray-800 border border-gray-300 shadow-sm px-4 h-10 text-sm rounded-lg dark:bg-gray-800 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-700">Close</button>
        </template>
    </inbox-stack>
</template>

<script>
import InboxStack from './InboxStack.vue';

export default {
    components: {
        InboxStack
    },
    props: {
        open: {
            type: Boolean,
            required: true
        },
        content: {
            type: [String, Object],
            default: ''
        }
    },
    methods: {
        highlightJson(json) {
            if (typeof json !== 'string') {
                json = JSON.stringify(json, undefined, 2);
            }
            if (!json) return '';
            
            json = json.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            return json.replace(/("(\\u[a-zA-Z0-9]{4}|\\[^u]|[^\\"])*"(\s*:)?|\b(true|false|null)\b|-?\d+(?:\.\d*)?(?:[eE][+\-]?\d+)?)/g, function (match) {
                var cls = 'ap-json-number';
                if (/^"/.test(match)) {
                    if (/:$/.test(match)) {
                        cls = 'ap-json-key';
                    } else {
                        cls = 'ap-json-string';
                    }
                } else if (/true|false/.test(match)) {
                    cls = 'ap-json-bool';
                } else if (/null/.test(match)) {
                    cls = 'ap-json-null';
                }
                return '<span class="' + cls + '">' + match + '</span>';
            });
        }
    }
}
</script>

<style>
/* JSON Viewer */
.ap-json-viewer {
    font-family: 'SF Mono', 'Fira Code', 'Fira Mono', 'Roboto Mono', monospace;
    font-size: 0.8125rem;
    line-height: 1.5;
    white-space: pre;
    overflow: auto;
    background-color: #fafafa;
    color: #171717;
    border-radius: 0.375rem;
    padding: 1rem;
}
.ap-json-key   { color: #db2777; }
.ap-json-string { color: #16a34a; }
.ap-json-number { color: #2563eb; }
.ap-json-bool  { color: #ea580c; }
.ap-json-null  { color: #737373; font-style: italic; }

html.dark .ap-json-viewer,
html.is-dark .ap-json-viewer,
html.isdark .ap-json-viewer {
    background-color: #171717;
    color: #e5e5e5;
}
html.dark .ap-json-key,
html.is-dark .ap-json-key,
html.isdark .ap-json-key   { color: #f38ba8; }
html.dark .ap-json-string,
html.is-dark .ap-json-string,
html.isdark .ap-json-string { color: #a6e3a1; }
html.dark .ap-json-number,
html.is-dark .ap-json-number,
html.isdark .ap-json-number { color: #89b4fa; }
html.dark .ap-json-bool,
html.is-dark .ap-json-bool,
html.isdark .ap-json-bool  { color: #fab387; }
html.dark .ap-json-null,
html.is-dark .ap-json-null,
html.isdark .ap-json-null  { color: #a3a3a3; font-style: italic; }
</style>
