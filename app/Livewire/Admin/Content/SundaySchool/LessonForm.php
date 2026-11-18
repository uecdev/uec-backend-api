<?php

namespace App\Livewire\Admin\Content\SundaySchool;

use App\Events\ContentPublished;
use App\Livewire\Admin\Concerns\AdminComponent;
use App\Models\SsLesson;
use App\Models\SsManual;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.admin')]
class LessonForm extends Component
{
    use AdminComponent;

    public int $year;

    public ?string $lessonId = null;

    public ?int $number = null;

    public string $title = '';

    public string $passage = '';

    public string $memory_verse_text = '';

    public string $memory_verse_ref = '';

    public string $aim = '';

    public string $introduction = '';

    /** @var list<array{heading: string, body: string}> */
    public array $outline = [];

    /** @var list<string> */
    public array $applications = [];

    public string $conclusion = '';

    /** @var list<string> */
    public array $revision_questions = [];

    public function mount(int $year, ?string $lesson = null): void
    {
        $this->authorizeAbility('content.view');
        $this->year = SsManual::query()->findOrFail($year)->year;

        if ($lesson) {
            $m = SsLesson::query()->where('manual_year', $this->year)->findOrFail($lesson);
            $this->lessonId = $m->id;
            $this->number = $m->number;
            foreach (['title', 'passage', 'memory_verse_text', 'memory_verse_ref', 'aim', 'introduction', 'conclusion'] as $f) {
                $this->{$f} = (string) $m->{$f};
            }
            $this->outline = array_map(fn ($o) => ['heading' => (string) ($o['heading'] ?? ''), 'body' => (string) ($o['body'] ?? '')], $m->outline ?? []);
            $this->applications = array_values($m->applications ?? []);
            $this->revision_questions = array_values($m->revision_questions ?? []);
        } else {
            $this->number = (int) SsLesson::query()->where('manual_year', $this->year)->max('number') + 1;
            $this->outline = [['heading' => '', 'body' => '']];
            $this->applications = [''];
            $this->revision_questions = [''];
        }
    }

    public function addItem(string $list): void
    {
        match ($list) {
            'outline' => $this->outline[] = ['heading' => '', 'body' => ''],
            'applications' => $this->applications[] = '',
            'revision_questions' => $this->revision_questions[] = '',
        };
    }

    public function removeItem(string $list, int $index): void
    {
        if (in_array($list, ['outline', 'applications', 'revision_questions'], true)) {
            unset($this->{$list}[$index]);
            $this->{$list} = array_values($this->{$list});
        }
    }

    public function save(): void
    {
        $this->authorizeAbility('content.manage');
        $this->outline = array_values(array_filter($this->outline, fn ($o) => trim($o['heading'].$o['body']) !== ''));
        $this->applications = array_values(array_filter($this->applications, fn ($a) => trim($a) !== ''));
        $this->revision_questions = array_values(array_filter($this->revision_questions, fn ($q) => trim($q) !== ''));

        $data = $this->validate([
            'number' => ['required', 'integer', 'min:1', 'max:999', Rule::unique('ss_lessons', 'number')->where('manual_year', $this->year)->ignore($this->lessonId)],
            'title' => ['required', 'string', 'max:200'],
            'passage' => ['required', 'string', 'max:120'],
            'memory_verse_text' => ['nullable', 'string', 'max:2000'],
            'memory_verse_ref' => ['nullable', 'string', 'max:120', 'required_with:memory_verse_text'],
            'aim' => ['nullable', 'string', 'max:5000'],
            'introduction' => ['nullable', 'string', 'max:20000'],
            'outline' => ['array', 'max:30'],
            'outline.*.heading' => ['required', 'string', 'max:255'],
            'outline.*.body' => ['nullable', 'string', 'max:20000'],
            'applications' => ['array', 'max:30'],
            'applications.*' => ['string', 'max:5000'],
            'conclusion' => ['nullable', 'string', 'max:10000'],
            'revision_questions' => ['array', 'max:30'],
            'revision_questions.*' => ['string', 'max:1000'],
        ], ['number.unique' => 'This lesson number already exists in the manual.'], ['outline.*.heading' => 'outline heading']);

        $lesson = $this->lessonId ? SsLesson::query()->findOrFail($this->lessonId) : new SsLesson(['manual_year' => $this->year]);
        $lesson->fill(array_map(fn ($v) => is_string($v) && trim($v) === '' ? null : $v, $data))->save();

        ContentPublished::dispatch('sunday_school', $this->year);
        $this->audit($this->lessonId ? 'ss_lesson.updated' : 'ss_lesson.created', $lesson, "Lesson {$lesson->number}: {$lesson->title}");
        session()->flash('toast', ['type' => 'success', 'message' => 'Lesson saved.']);
        $this->redirectRoute('admin.sunday-school.manual', ['year' => $this->year], navigate: true);
    }

    public function render()
    {
        return view('livewire.admin.content.sunday-school.lesson-form')->title($this->lessonId ? "Edit lesson {$this->number}" : 'New lesson');
    }
}
