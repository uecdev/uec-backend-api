<?php

namespace App\Livewire\Admin\Content\SundaySchool;

use App\Events\ContentPublished;
use App\Livewire\Admin\Concerns\AdminComponent;
use App\Models\SsLesson;
use App\Models\SsManual;
use App\Models\SsPlanWeek;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.admin')]
class Manual extends Component
{
    use AdminComponent;

    public int $year;

    #[Url]
    public string $tab = 'lessons';

    public bool $showWeek = false;

    public ?int $weekId = null;

    public string $weekDate = '';

    public string $weekLessonId = '';

    public string $weekDescription = '';

    public function mount(int $year): void
    {
        $this->authorizeAbility('content.view');
        $this->year = SsManual::query()->findOrFail($year)->year;
    }

    public function deleteLesson(string $id): void
    {
        $this->authorizeAbility('content.manage');
        $lesson = SsLesson::query()->where('manual_year', $this->year)->findOrFail($id);
        $this->audit('ss_lesson.deleted', $lesson, "Lesson {$lesson->number}: {$lesson->title}");
        $lesson->delete();
        ContentPublished::dispatch('sunday_school', $this->year);
        $this->toast('Lesson deleted.');
    }

    public function newWeek(): void
    {
        $this->authorizeAbility('content.manage');
        $this->resetValidation();
        $last = SsPlanWeek::query()->where('manual_year', $this->year)->max('date');
        $next = $last ? Carbon::parse($last)->addWeek() : Carbon::create($this->year, 1, 1);
        if (! $next->isSunday()) {
            $next = $next->next(Carbon::SUNDAY); // first Sunday of the year
        }
        $this->fill(['weekId' => null, 'weekDate' => $next->toDateString(), 'weekLessonId' => '', 'weekDescription' => '', 'showWeek' => true]);
    }

    public function editWeek(int $id): void
    {
        $this->authorizeAbility('content.manage');
        $this->resetValidation();
        $w = SsPlanWeek::query()->where('manual_year', $this->year)->findOrFail($id);
        $this->fill(['weekId' => $w->id, 'weekDate' => $w->date->toDateString(), 'weekLessonId' => (string) $w->lesson_id, 'weekDescription' => $w->description, 'showWeek' => true]);
    }

    public function saveWeek(): void
    {
        $this->authorizeAbility('content.manage');
        $data = $this->validate([
            'weekDate' => ['required', 'date_format:Y-m-d', Rule::unique('ss_plan_weeks', 'date')->where('manual_year', $this->year)->ignore($this->weekId),
                function ($attr, $value, $fail) {
                    if (! Carbon::parse($value)->isSunday()) {
                        $fail('Plan dates must be Sundays.');
                    }
                }],
            'weekLessonId' => ['nullable', Rule::exists('ss_lessons', 'id')->where('manual_year', $this->year)],
            'weekDescription' => ['required', 'string', 'max:255'],
        ]);

        $date = Carbon::parse($data['weekDate']);
        $week = $this->weekId ? SsPlanWeek::query()->where('manual_year', $this->year)->findOrFail($this->weekId) : new SsPlanWeek;
        $week->fill([
            'manual_year' => $this->year,
            'date' => $date->toDateString(),
            'week' => (int) ceil($date->day / 7),
            'lesson_id' => $data['weekLessonId'] ?: null,
            'description' => $data['weekDescription'],
        ])->save();

        ContentPublished::dispatch('sunday_school', $this->year);
        $this->audit('ss_plan.updated', SsManual::query()->find($this->year), $date->toDateString().' '.$data['weekDescription']);
        $this->showWeek = false;
        $this->toast('Plan updated.');
    }

    public function deleteWeek(int $id): void
    {
        $this->authorizeAbility('content.manage');
        SsPlanWeek::query()->where('manual_year', $this->year)->whereKey($id)->delete();
        ContentPublished::dispatch('sunday_school', $this->year);
        $this->toast('Week removed from the plan.');
    }

    public function render()
    {
        $manual = SsManual::query()->findOrFail($this->year);

        return view('livewire.admin.content.sunday-school.manual', [
            'manual' => $manual,
            'lessons' => SsLesson::query()->where('manual_year', $this->year)->orderBy('number')->get(),
            'weeks' => SsPlanWeek::query()->where('manual_year', $this->year)->with('lesson:id,number,title')->orderBy('date')->get(),
        ])->title($manual->title);
    }
}
