<template>
    <div class="card p-0 overflow-hidden">
        <div class="flex flex-col divide-y divide-gray-100 dark:divide-gray-800">
            <div v-for="activity in activityData" :key="activity.id" class="p-4 flex gap-4 hover:bg-gray-50 dark:hover:bg-gray-900 transition-colors group">
                 <!-- Avatar -->
                <div class="flex-shrink-0 pt-1">
                    <img v-if="activity.avatar" :src="activity.avatar" class="w-10 h-10 rounded-full object-cover bg-gray-200 dark:bg-gray-700">
                    <div v-else class="w-10 h-10 rounded-full bg-gray-200 dark:bg-gray-700 flex items-center justify-center text-gray-400 font-bold text-sm">
                        {{ activity.actorName.charAt(0) }}
                    </div>
                </div>

                <div class="flex-1 min-w-0">
                    <div class="flex items-center justify-between">
                         <div class="flex items-center gap-1 min-w-0">
                             <span class="font-bold text-gray-900 dark:text-gray-100 truncate text-sm">{{ activity.actorName }}</span>
                             <span class="text-gray-500 text-sm truncate" :title="activity.actorHandle">{{ activity.actorHandle }}</span>
                             <span class="text-gray-400 text-xs">&bull;</span>
                             <span class="text-gray-400 text-xs whitespace-nowrap">{{ activity.date }}</span>
                         </div>
                         
                         <!-- Actions -->
                         <div class="flex items-center gap-2 opacity-50 group-hover:opacity-100 transition-opacity">
                             <button type="button" @click="showJson(activity.id)" class="text-gray-400 hover:text-blue-500" title="View JSON">
                                 <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                                     <path fill-rule="evenodd" d="M12.316 3.051a1 1 0 01.633 1.265l-4 12a1 1 0 11-1.898-.632l4-12a1 1 0 011.265-.633zM5.707 6.293a1 1 0 010 1.414L3.414 10l2.293 2.293a1 1 0 11-1.414 1.414l-3-3a1 1 0 010-1.414l3-3a1 1 0 011.414 0zm8.586 0a1 1 0 011.414 0l3 3a1 1 0 010 1.414l-3 3a1 1 0 11-1.414-1.414L16.586 10l-2.293-2.293a1 1 0 010-1.414z" clip-rule="evenodd" />
                                 </svg>
                             </button>
                         </div>
                    </div>

                     <div class="mt-1 flex items-start gap-2 text-sm text-gray-700 dark:text-gray-300">
                         <span class="text-gray-400 mt-0.5" v-html="activity.icon"></span>
                         <div class="prose prose-sm dark:prose-invert max-w-none leading-snug" v-html="activity.description"></div>
                     </div>
                </div>
            </div>
        </div>

        <div class="p-4 border-t border-gray-100 dark:border-gray-800 bg-gray-50 dark:bg-gray-900/50">
             <!-- Pagination is handled by Blade for now passed as links string, or slot -->
             <!-- For this refactor, we are mostly replacing the list logic, but pagination links coming from Laravel default are HTML. -->
             <!-- If we passed the full HTML links, we can render them. -->
             <div v-html="pagination" class="pagination-container"></div>
        </div>
        
        <!-- Slide-over / Stack -->
        <div v-if="drawer.open" class="fixed inset-0 z-[100] z-[99]" @keydown.esc="closeJson">
             <div class="absolute inset-0 bg-gray-900/50 backdrop-blur-sm transition-opacity" @click="closeJson"></div>
             
             <div class="absolute inset-y-0 right-0 max-w-2xl w-full bg-white dark:bg-gray-900 shadow-xl flex flex-col transform transition-transform duration-300 ease-spring drawer"
                  :class="{'translate-x-full': !drawer.visible, 'translate-x-0': drawer.visible}">
                  
                  <!-- Header -->
                  <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100 dark:border-gray-800 shrink-0 bg-white dark:bg-gray-900">
                      <div class="flex flex-col gap-1">
                          <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Payload Inspector</h2>
                          <span class="text-xs font-mono text-gray-500">{{ drawer.uuid }}</span>
                      </div>
                      <button @click="closeJson" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 p-2 rounded-md hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors">
                          <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                      </button>
                  </div>
                  
                  <!-- Content -->
                  <div class="flex-1 overflow-auto bg-gray-50 dark:bg-gray-950 p-6">
                      <div class="bg-white dark:bg-gray-900 rounded-lg shadow-sm border border-gray-200 dark:border-gray-800 overflow-hidden">
                          <pre class="p-4 text-xs font-mono leading-relaxed text-gray-800 dark:text-gray-200 overflow-x-auto"><code class="language-json">{{ drawer.content }}</code></pre>
                      </div>
                  </div>
                  
                  <!-- Footer -->
                  <div class="px-6 py-4 border-t border-gray-100 dark:border-gray-800 bg-gray-50 dark:bg-gray-900 shrink-0 flex justify-end">
                      <button @click="closeJson" class="btn">Close</button>
                  </div>
             </div>
        </div>
    </div>
</template>

<script>
export default {
    props: {
        activities: {
            type: Array, // Expected to be serialized array of simplified objects from Presenter
            required: true
        },
        pagination: {
            type: String,
            default: ''
        }
    },
    data() {
        return {
            activityData: this.activities,
            drawer: {
                open: false,
                visible: false, // for animation
                uuid: '',
                content: ''
            }
        }
    },
    methods: {
        showJson(uuid) {
            const item = this.activityData.find(a => a.id === uuid);
            if (!item || !item.json) return;
            
            this.drawer.uuid = uuid;
            this.drawer.content = JSON.stringify(item.json, null, 2);
            this.drawer.open = true;
            document.body.style.overflow = 'hidden'; // Prevent body scroll
            
            // slight delay for animation
            setTimeout(() => {
                this.drawer.visible = true;
            }, 10);
        },
        closeJson() {
            this.drawer.visible = false;
            setTimeout(() => {
                this.drawer.open = false;
                document.body.style.overflow = '';
            }, 300);
        }
    }
}
</script>
<style scoped>
.pagination-container nav {
    display: flex;
    justify-content: center;
}
.ease-spring {
    transition-timing-function: cubic-bezier(0.16, 1, 0.3, 1);
}
.drawer {
    height: calc(100vh - 3.25rem);
    margin-top: 3.25rem;
}
</style>
