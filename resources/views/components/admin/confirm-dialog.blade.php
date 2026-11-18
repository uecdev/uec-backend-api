{{-- Global confirmation dialog: $store.confirm.ask({ title, message, confirmLabel, danger, onConfirm }) --}}
<div x-data x-cloak x-show="$store.confirm.open" class="fixed inset-0 z-50 flex items-end justify-center p-4 sm:items-center"
     role="alertdialog" aria-modal="true" aria-labelledby="confirm-title" aria-describedby="confirm-message"
     @keydown.escape.window="$store.confirm.cancel()">
    <div x-show="$store.confirm.open" x-transition.opacity class="absolute inset-0 bg-brand-950/50" @click="$store.confirm.cancel()"></div>
    <div x-show="$store.confirm.open" x-transition class="card relative w-full max-w-md p-6" x-trap.noscroll="$store.confirm.open">
        <div class="flex gap-4">
            <div class="grid size-11 shrink-0 place-items-center rounded-full" :class="$store.confirm.danger ? 'bg-red-50 text-red-700' : 'bg-brand-50 text-brand-700'">
                <x-admin.icon name="alert" />
            </div>
            <div>
                <h2 id="confirm-title" class="text-base font-black" x-text="$store.confirm.title"></h2>
                <p id="confirm-message" class="mt-1.5 text-sm text-muted" x-text="$store.confirm.message"></p>
            </div>
        </div>
        <div class="mt-6 flex justify-end gap-2">
            <button type="button" class="btn-secondary" @click="$store.confirm.cancel()">Cancel</button>
            <button type="button" :class="$store.confirm.danger ? 'btn-danger' : 'btn-primary'" @click="$store.confirm.accept()" x-text="$store.confirm.confirmLabel"></button>
        </div>
    </div>
</div>
