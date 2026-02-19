<template>
    <div class="flex flex-col gap-6">
         <settings-panel title="Domain Blocklist" description="Prevent interactions with specific domains.">
            <textarea v-model="form.blocklist" class="input-text w-full h-64 font-mono text-sm" placeholder="example.com\nspam.server.social" rows="25"></textarea>
            <p class="text-xs text-gray-500 mt-2">
                One domain per line. Subdomains are automatically blocked if the parent is listed.
                See <a href="https://github.com/gardenfence/blocklist/blob/main/gardenfence.txt" target="_blank" class="text-blue-500 hover:underline">GardenFence</a> for a starter list.
            </p>
        </settings-panel>
    </div>
</template>

<script>
import SettingsPanel from './SettingsPanel.vue';

export default {
    components: { SettingsPanel },
    props: {
        form: Object
    }
}
</script>
