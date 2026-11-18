import { Alpine, Livewire } from '../../vendor/livewire/livewire/dist/livewire.esm';
import type { ChartPayload } from './charts';

/*
 * UEC administration dashboard client.
 * Livewire's ESM build is bundled here so Alpine components are registered
 * before Alpine starts (Blade uses @livewireScriptConfig, not @livewireScripts).
 */

interface ConfirmRequest {
    title: string;
    message: string;
    confirmLabel?: string;
    danger?: boolean;
    onConfirm: () => void;
}

interface Toast {
    id: number;
    type: 'success' | 'error' | 'info';
    message: string;
}

// ---- Confirmation dialog for critical actions --------------------------------
Alpine.store('confirm', {
    open: false,
    title: '',
    message: '',
    confirmLabel: 'Confirm',
    danger: true,
    onConfirm: null as null | (() => void),
    ask(this: { open: boolean; title: string; message: string; confirmLabel: string; danger: boolean; onConfirm: null | (() => void) }, req: ConfirmRequest) {
        this.title = req.title;
        this.message = req.message;
        this.confirmLabel = req.confirmLabel ?? 'Confirm';
        this.danger = req.danger ?? true;
        this.onConfirm = req.onConfirm;
        this.open = true;
    },
    accept(this: { open: boolean; onConfirm: null | (() => void) }) {
        const action = this.onConfirm;
        this.open = false;
        this.onConfirm = null;
        action?.();
    },
    cancel(this: { open: boolean; onConfirm: null | (() => void) }) {
        this.open = false;
        this.onConfirm = null;
    },
});

// ---- Toast notifications -------------------------------------------------------
let toastId = 0;
Alpine.store('toasts', {
    items: [] as Toast[],
    push(this: { items: Toast[]; dismiss: (id: number) => void }, type: Toast['type'], message: string) {
        const id = ++toastId;
        this.items.push({ id, type, message });
        window.setTimeout(() => this.dismiss(id), type === 'error' ? 8000 : 4500);
    },
    dismiss(this: { items: Toast[] }, id: number) {
        this.items = this.items.filter((t) => t.id !== id);
    },
});

// Livewire components dispatch `toast` events: $this->dispatch('toast', type: 'success', message: '…')
window.addEventListener('toast', (event: Event) => {
    const detail = (event as CustomEvent<{ type?: Toast['type']; message?: string } | Array<{ type?: Toast['type']; message?: string }>>).detail;
    const payload = Array.isArray(detail) ? detail[0] : detail;
    if (payload?.message) {
        Alpine.store<{ push: (t: Toast['type'], m: string) => void }>('toasts').push(payload.type ?? 'success', payload.message);
    }
});

// ---- Chart component: <div x-data="chart(@js($payload))"><canvas x-ref="canvas"></canvas></div>
// Chart.js is code-split and only downloaded on pages that render a chart.
Alpine.data('chart', (payload: ChartPayload) => {
    let instance: import('chart.js').Chart | null = null;
    let charts: typeof import('./charts') | null = null;

    return {
        async init(this: { $refs: Record<string, HTMLElement> }) {
            const canvas = this.$refs.canvas as HTMLCanvasElement | undefined;
            if (!canvas) return;
            charts = await import('./charts');
            if (!canvas.isConnected) return; // component removed while loading
            instance = new charts.Chart(canvas, charts.buildConfig(payload));
        },
        update(next: ChartPayload) {
            if (!instance || !charts) return;
            instance.data = charts.buildConfig(next).data;
            instance.update();
        },
        destroy() {
            instance?.destroy();
            instance = null;
        },
    };
});

// ---- Copy-to-clipboard helper ----------------------------------------------------
Alpine.data('clipboard', (text: string) => ({
    copied: false,
    async copy(this: { copied: boolean }) {
        try {
            await navigator.clipboard.writeText(text);
            this.copied = true;
            window.setTimeout(() => (this.copied = false), 1500);
        } catch {
            this.copied = false;
        }
    },
}));

// Surface server errors from Livewire requests as a toast instead of a blank modal.
Livewire.hook('request', (...args: unknown[]) => {
    const { fail } = (args[0] ?? {}) as { fail?: (cb: (ctx: { status: number; preventDefault: () => void }) => void) => void };
    fail?.(({ status, preventDefault }) => {
        if (status === 419) {
            preventDefault();
            Alpine.store<{ push: (t: Toast['type'], m: string) => void }>('toasts').push('error', 'Your session expired. Reload the page to continue.');
        } else if (status >= 500) {
            preventDefault();
            Alpine.store<{ push: (t: Toast['type'], m: string) => void }>('toasts').push('error', 'Something went wrong. Please try again.');
        }
    });
});

Livewire.start();
