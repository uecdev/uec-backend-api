<div>
    <x-admin.page-header title="Bible" description="Translations served to the reader and the scripture quick-peek. KJV is public domain; any other version needs a licence before it is enabled." />

    <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
        <section class="card xl:col-span-2">
            <div class="card-header"><h2 class="card-title">Installed versions</h2></div>
            <div class="overflow-x-auto">
                <table class="table">
                    <thead><tr><th>Version</th><th>Coverage</th><th>Licence</th><th>Status</th><th class="text-right"><span class="sr-only">Actions</span></th></tr></thead>
                    <tbody>
                    @forelse ($versions as $v)
                        @php $c = $counts[$v->id] ?? null; @endphp
                        <tr wire:key="bv-{{ $v->id }}">
                            <td><span class="font-black">{{ $v->id }}</span> <span class="block text-xs text-muted">{{ $v->name }}</span></td>
                            <td class="text-sm">{{ number_format($c->verses ?? 0) }} verses · {{ $c->books ?? 0 }}/{{ $bookCount }} books</td>
                            <td class="text-xs text-muted">{{ $v->licence_note ?: '—' }}</td>
                            <td>@if ($v->is_active)<x-admin.status value="active" />@else<x-admin.status value="draft" />@endif</td>
                            <td class="text-right">@can('content.manage')<button type="button" class="btn-ghost btn-sm" wire:click="toggle('{{ $v->id }}')">{{ $v->is_active ? 'Hide' : 'Enable' }}</button>@endcan</td>
                        </tr>
                    @empty
                        <x-admin.empty title="No Bible text imported" icon="bible" colspan="5">Import a translation with the command shown alongside.</x-admin.empty>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>
        <section class="card p-5">
            <h2 class="card-title">Importing a translation</h2>
            <p class="mt-2 text-sm text-muted">Bible text is imported by an engineer on the API server (about 31,000 verses), then managed here.</p>
            <pre class="mt-4 overflow-x-auto rounded-xl bg-brand-900 p-4 text-xs text-brand-50"><code>php artisan uec:bible:import KJV kjv.json \
  --name="King James Version" \
  --licence="Public domain"</code></pre>
            <p class="mt-3 text-xs text-muted">Accepts JSON rows <code>{book, chapter, verse, text}</code> with USFM book codes, the common 66-book nested JSON layout, or CSV <code>book,chapter,verse,text</code>. See docs/INTEGRATIONS.md.</p>
        </section>
    </div>
</div>
