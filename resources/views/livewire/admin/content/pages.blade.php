<div>
    <x-admin.page-header title="Content pages" description="About, Anthem and Doctrine appear under More in the app. Terms and Privacy are linked from the paywall (NDPA requirement)." />

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-4">
        <nav class="card h-fit p-2" aria-label="Pages">
            @foreach ($labels as $key => $label)
                @php $p = $pages[$key] ?? null; @endphp
                <button type="button" wire:click="$set('slug', '{{ $key }}')" @class(['flex w-full items-center justify-between gap-2 rounded-xl px-3 py-2.5 text-left text-sm font-bold transition', 'bg-brand-50 text-brand-800' => $slug === $key, 'hover:bg-cream' => $slug !== $key])>
                    <span>{{ $label }}</span>
                    @if ($p?->isPublished())<span class="size-2 rounded-full bg-brand-500" title="Published"></span>@elseif ($p)<span class="size-2 rounded-full bg-gold-500" title="Draft"></span>@else<span class="text-xs font-normal text-muted">Empty</span>@endif
                </button>
            @endforeach
        </nav>

        <section class="card lg:col-span-3" wire:key="page-{{ $slug }}">
            <div class="card-header">
                <div>
                    <h2 class="card-title">{{ $labels[$slug] }}</h2>
                    @if ($pages[$slug] ?? null)<p class="text-xs text-muted">Last updated {{ $pages[$slug]->updated_at->diffForHumans() }}{{ $pages[$slug]->editor ? ' by '.$pages[$slug]->editor->displayName() : '' }}</p>@endif
                </div>
                @can('content.manage')
                    <div class="flex items-center gap-3">
                        <label class="flex items-center gap-2 text-sm font-bold"><input type="checkbox" wire:model="published" class="size-4 rounded border-line text-brand-700"> Published</label>
                        <button type="button" class="btn-primary" wire:click="save"><x-admin.icon name="check" class="size-4" /> Save</button>
                    </div>
                @endcan
            </div>
            <fieldset @disabled(! auth()->user()->can('content.manage')) class="space-y-4 p-5">
                <x-admin.field label="Title" for="ptitle" error="title" required><input id="ptitle" type="text" wire:model="title" class="input"></x-admin.field>
                <x-admin.rich-text model="body" label="Content" :rows="20" required />
            </fieldset>
        </section>
    </div>
</div>
