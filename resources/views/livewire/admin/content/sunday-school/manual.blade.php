<div>
    <x-admin.page-header :title="$manual->title" :description="$manual->theme" :back="route('admin.sunday-school.index')">
        <x-slot:actions>
            @can('content.manage')
                @if ($tab === 'lessons')
                    <a href="{{ route('admin.sunday-school.lesson.create', $year) }}" wire:navigate class="btn-primary"><x-admin.icon name="plus" class="size-4" /> New lesson</a>
                @else
                    <button type="button" wire:click="newWeek" class="btn-primary"><x-admin.icon name="plus" class="size-4" /> Add Sunday</button>
                @endif
            @endcan
        </x-slot:actions>
    </x-admin.page-header>

    <div class="mb-4 inline-flex rounded-xl border border-line bg-cream p-1" role="tablist">
        <button type="button" role="tab" wire:click="$set('tab', 'lessons')" @class(['tab', 'tab-active' => $tab === 'lessons'])>Lessons ({{ $lessons->count() }})</button>
        <button type="button" role="tab" wire:click="$set('tab', 'plan')" @class(['tab', 'tab-active' => $tab === 'plan'])>Lesson plan ({{ $weeks->count() }})</button>
    </div>

    @if ($tab === 'lessons')
        <div class="card overflow-x-auto">
            <table class="table">
                <thead><tr><th>No.</th><th>Lesson</th><th>Passage</th><th>Outline</th><th class="text-right"><span class="sr-only">Actions</span></th></tr></thead>
                <tbody>
                @forelse ($lessons as $lesson)
                    <tr wire:key="l-{{ $lesson->id }}">
                        <td class="font-black text-brand-700">{{ $lesson->number }}</td>
                        <td class="max-w-md"><a href="{{ route('admin.sunday-school.lesson.edit', [$year, $lesson->id]) }}" wire:navigate class="block truncate font-bold hover:text-brand-700">{{ $lesson->title }}</a></td>
                        <td class="whitespace-nowrap text-muted">{{ $lesson->passage }}</td>
                        <td class="text-xs text-muted">{{ count($lesson->outline ?? []) }} points · {{ count($lesson->revision_questions ?? []) }} questions</td>
                        <td class="text-right whitespace-nowrap">
                            @can('content.manage')
                                <a href="{{ route('admin.sunday-school.lesson.edit', [$year, $lesson->id]) }}" wire:navigate class="btn-ghost btn-sm" aria-label="Edit"><x-admin.icon name="edit" class="size-4" /></a>
                                <button type="button" class="btn-ghost btn-sm text-red-700" aria-label="Delete"
                                        @click="$store.confirm.ask({ title: 'Delete lesson {{ $lesson->number }}?', message: 'Plan weeks that point to it will keep their description but lose the link.', confirmLabel: 'Delete lesson', onConfirm: () => $wire.deleteLesson('{{ $lesson->id }}') })"><x-admin.icon name="trash" class="size-4" /></button>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <x-admin.empty title="No lessons yet" icon="school" colspan="5" />
                @endforelse
                </tbody>
            </table>
        </div>
    @else
        <div class="card overflow-x-auto">
            <table class="table">
                <thead><tr><th>Sunday</th><th>Week</th><th>Description</th><th>Lesson</th><th class="text-right"><span class="sr-only">Actions</span></th></tr></thead>
                <tbody>
                @forelse ($weeks as $w)
                    <tr wire:key="w-{{ $w->id }}">
                        <td class="font-bold whitespace-nowrap">{{ $w->date->format('j M Y') }}</td>
                        <td class="text-muted">Week {{ $w->week }}</td>
                        <td>{{ $w->description }}</td>
                        <td class="text-muted">{{ $w->lesson ? 'Lesson '.$w->lesson->number : '—' }}</td>
                        <td class="text-right whitespace-nowrap">
                            @can('content.manage')
                                <button type="button" class="btn-ghost btn-sm" wire:click="editWeek({{ $w->id }})" aria-label="Edit"><x-admin.icon name="edit" class="size-4" /></button>
                                <button type="button" class="btn-ghost btn-sm text-red-700" aria-label="Remove" wire:click="deleteWeek({{ $w->id }})" wire:confirm="Remove this Sunday from the plan?"><x-admin.icon name="trash" class="size-4" /></button>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <x-admin.empty title="No plan yet" icon="calendar" colspan="5">Add one row per Sunday, e.g. “Lesson 4: Outline 2 to 3”.</x-admin.empty>
                @endforelse
                </tbody>
            </table>
        </div>
    @endif

    <x-admin.modal show="showWeek" :title="$weekId ? 'Edit plan week' : 'Add Sunday to plan'" size="max-w-lg">
        <div class="space-y-4">
            <x-admin.field label="Sunday" for="weekDate" error="weekDate" required><input id="weekDate" type="date" wire:model="weekDate" class="input"></x-admin.field>
            <x-admin.field label="Lesson" for="weekLesson" error="weekLessonId" help="Leave empty for special Sundays (e.g. rededication service).">
                <select id="weekLesson" wire:model="weekLessonId" class="input">
                    <option value="">No lesson</option>
                    @foreach ($lessons as $l)<option value="{{ $l->id }}">Lesson {{ $l->number }}: {{ $l->title }}</option>@endforeach
                </select>
            </x-admin.field>
            <x-admin.field label="Description" for="weekDesc" error="weekDescription" required help="e.g. Lesson 1: Topic to Outline 1"><input id="weekDesc" type="text" wire:model="weekDescription" class="input"></x-admin.field>
        </div>
        <x-slot:footer>
            <button type="button" class="btn-secondary" @click="open = false">Cancel</button>
            <button type="button" class="btn-primary" wire:click="saveWeek">Save</button>
        </x-slot:footer>
    </x-admin.modal>
</div>
