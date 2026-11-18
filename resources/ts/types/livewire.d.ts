// Minimal typings for Livewire's ESM build (it ships without declarations).
declare module '*/livewire.esm' {
    export interface AlpineMagics {
        $dispatch(event: string, detail?: unknown): void;
    }

    export interface AlpineStatic {
        data(name: string, factory: (...args: never[]) => Record<string, unknown>): void;
        store(name: string, value: Record<string, unknown>): void;
        store<T>(name: string): T;
    }

    export interface LivewireStatic {
        start(): void;
        hook(name: string, callback: (...args: unknown[]) => void): void;
        on(event: string, callback: (...args: unknown[]) => void): void;
    }

    export const Alpine: AlpineStatic;
    export const Livewire: LivewireStatic;
}
