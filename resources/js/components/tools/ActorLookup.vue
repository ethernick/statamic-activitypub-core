<template>
    <div class="card p-0 overflow-hidden">
        <div class="p-4 border-b">
            <h2 class="text-lg font-bold mb-2">Actor Lookup</h2>
            <p class="text-sm text-grey-60 w-full mb-4">
                Enter an ActivityPub handle (e.g., <code>@user@domain.com</code>) or a direct Actor URL to test discovery and connectivity.
            </p>

            <div class="flex items-center gap-2">
                <input
                    type="text"
                    v-model="handle"
                    class="input-text flex-1"
                    placeholder="@username@domain.com or https://example.com/users/username"
                    @keyup.enter="lookup('actor')"
                />
                <button class="btn" @click="lookup('webfinger')" :disabled="loading">
                    Webfinger Only
                </button>
                <button class="btn-primary" @click="lookup('actor')" :disabled="loading">
                    Fetch Actor
                </button>
            </div>
        </div>

        <div v-if="loading" class="p-8 text-center">
            <span class="loading"></span>
        </div>

        <div v-if="error" class="p-4 bg-red-10 border-l-4 border-red text-red text-sm">
            {{ error }}
        </div>

        <div v-if="result" class="p-4 bg-grey-10">
            <div class="flex items-center justify-between mb-2">
                <h3 class="font-bold uppercase text-xs text-grey-60 tracking-wider">
                    {{ resultType === 'webfinger' ? 'Webfinger Response' : 'Actor Profile' }}
                </h3>
                <button class="btn-xs" @click="copyToClipboard">Copy JSON</button>
            </div>
            <pre class="bg-black text-green p-4 rounded text-xs overflow-x-auto max-h-96"><code>{{ JSON.stringify(result, null, 2) }}</code></pre>
        </div>
    </div>
</template>

<script>
export default {
    props: {
        lookupUrl: String
    },

    data() {
        return {
            handle: '',
            loading: false,
            result: null,
            resultType: null,
            error: null
        }
    },

    methods: {
        lookup(type) {
            if (!this.handle) return;

            this.loading = true;
            this.result = null;
            this.error = null;
            this.resultType = type;

            this.$axios.post(this.lookupUrl, {
                handle: this.handle,
                type: type
            }).then(response => {
                this.result = response.data;
                this.loading = false;
            }).catch(error => {
                this.error = error.response?.data?.message || 'An error occurred during lookup.';
                this.loading = false;
            });
        },

        copyToClipboard() {
            const text = JSON.stringify(this.result, null, 2);
            navigator.clipboard.writeText(text).then(() => {
                Statamic.$toast.success('Copied to clipboard');
            });
        }
    }
}
</script>
