<div>
    <x-admin.page-header title="Sunday School Manual" description="Yearly manuals with numbered lessons and a weekly lesson plan. The app computes “this week’s lesson” from the plan.">
        <x-slot:actions>
            @can('content.manage')<button type="button" wire:click="create" class="btn-primary"><x-admin.icon name="plus" class="size-4" /> New manual</button>@endcan
        </x-slot:actions>
    </x-admin.page-header>

    <div class="grid grid-cols-1 gap-5 md:grid-cols-2 xl:grid-cols-3">
        @forelse ($manuals as $m)
            <article class="card flex flex-col" wire:key="m-{{ $m->year }}">
                <div class="flex-1 p-5">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <div class="text-3xl font-black text-brand-700">{{ $m->year }}</div>
                            <h2 class="mt-1 font-black">{{ $m->title }}</h2>
                            <p class="text-sm text-muted">{{ $m->theme ?: 'No theme set' }}</p>
                        </div>
                        <div class="flex flex-col items-end gap-1">
                            @if ($m->isPublished())<x-admin.status value="published" />@else<x-admin.status value="draft" />@endif
                            @if ($m->is_premium)<span class="badge bg-gold-50 text-gold-700">Premium</span>@endif
                        </div>
                    </div>
                    <dl class="mt-5 grid grid-cols-2 gap-3 text-sm">
                        <div class="rounded-xl bg-cream px-3 py-2"><dt class="text-xs text-muted">Lessons</dt><dd class="font-black">{{ $m->lessons_count }}</dd></div>
                        <div class="rounded-xl bg-cream px-3 py-2"><dt class="text-xs text-muted">Plan weeks</dt><dd class="font-black">{{ $m->plan_weeks_count }}</dd></div>
                    </dl>
                </div>
                <div class="flex gap-2 border-t border-line p-3">
                    <a href="{{ route('admin.sunday-school.manual', $m->year) }}" wire:navigate class="btn-secondary flex-1">Lessons & plan</a>
                    @can('content.manage')<button type="button" wire:click="edit({{ $m->year }})" class="btn-ghost" aria-label="Edit manual"><x-admin.icon name="edit" class="size-4" /></button>@endcan
                </div>
            </article>
        @empty
            <div class="card md:col-span-2 xl:col-span-3"><x-admin.empty title="No manuals yet" icon="school">Create a yearly manual, add its lessons, then build the weekly plan.</x-admin.empty></div>
        @endforelse
    </div>

    <x-admin.modal show="showForm" :title="$editingYear ? 'Edit manual' : 'New manual'" size="max-w-lg">
        <div class="space-y-4">
            <x-admin.field label="Year" for="year" error="year" required><input id="year" type="number" wire:model="year" class="input"></x-admin.field>
            <x-admin.field label="Title" for="mtitle" error="title" required><input id="mtitle" type="text" wire:model="title" class="input"></x-admin.field>
            <x-admin.field label="Theme" for="theme" error="theme"><input id="theme" type="text" wire:model="theme" class="input"></x-admin.field>
            <label class="flex items-start gap-3 text-sm"><input type="checkbox" wire:model="is_premium" class="mt-0.5 size-4 rounded border-line text-brand-700"><span><strong>Premium (archive)</strong><br><span class="text-muted">Only subscribers can read this manual.</span></span></label>
            <label class="flex items-start gap-3 text-sm"><input type="checkbox" wire:model="published" class="mt-0.5 size-4 rounded border-line text-brand-700"><span><strong>Published</strong><br><span class="text-muted">Visible in the app.</span></span></label>
        </div>
        <x-slot:footer>
            <button type="button" class="btn-secondary" @click="open = false">Cancel</button>
            <button type="button" class="btn-primary" wire:click="save">Save manual</button>
        </x-slot:footer>
    </x-admin.modal>
</div>
