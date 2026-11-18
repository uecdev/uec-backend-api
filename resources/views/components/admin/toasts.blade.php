<div x-data class="pointer-events-none fixed inset-x-0 bottom-0 z-[60] flex flex-col items-center gap-2 p-4 sm:items-end sm:p-6" aria-live="polite">
    <template x-for="toast in $store.toasts.items" :key="toast.id">
        <div x-transition class="pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-xl border bg-white px-4 py-3 shadow-lg"
             :class="{ 'border-brand-200': toast.type === 'success', 'border-red-200': toast.type === 'error', 'border-line': toast.type === 'info' }">
            <span class="mt-0.5 grid size-6 shrink-0 place-items-center rounded-full"
                  :class="{ 'bg-brand-50 text-brand-700': toast.type === 'success', 'bg-red-50 text-red-700': toast.type === 'error', 'bg-cream text-muted': toast.type === 'info' }">
                <template x-if="toast.type === 'success'"><x-admin.icon name="check" class="size-4" /></template>
                <template x-if="toast.type !== 'success'"><x-admin.icon name="info" class="size-4" /></template>
            </span>
            <p class="flex-1 text-sm font-bold text-ink" x-text="toast.message"></p>
            <button type="button" class="text-muted hover:text-ink" @click="$store.toasts.dismiss(toast.id)" aria-label="Dismiss">
                <x-admin.icon name="x" class="size-4" />
            </button>
        </div>
    </template>
</div>
