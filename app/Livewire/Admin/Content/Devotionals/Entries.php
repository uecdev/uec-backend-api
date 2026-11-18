<?php

namespace App\Livewire\Admin\Content\Devotionals;

use App\Events\ContentPublished;
use App\Livewire\Admin\Concerns\AdminComponent;
use App\Models\DevotionalEdition;
use App\Models\DevotionalEntry;
use Carbon\CarbonPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('layouts.admin')]
class Entries extends Component
{
    use AdminComponent, WithFileUploads;

    public int $year;

    #[Url]
    public int $month;

    public bool $showImport = false;

    public $importFile = null;

    /** @var list<string> */
    public array $importErrors = [];

    public function mount(int $year): void
    {
        $this->authorizeAbility('content.view');
        $this->year = DevotionalEdition::query()->findOrFail($year)->year;
        $this->month ??= now(config('uec.timezone'))->year === $year ? now(config('uec.timezone'))->month : 1;
    }

    public function delete(string $id): void
    {
        $this->authorizeAbility('content.manage');
        $entry = DevotionalEntry::query()->where('edition_year', $this->year)->findOrFail($id);
        $this->audit('devotional_entry.deleted', $entry, $entry->date->format('Y-m-d').' '.$entry->title);
        $entry->delete();
        ContentPublished::dispatch('devotional', $this->year);
        $this->toast('Entry deleted.');
    }

    /**
     * CSV import (UTF-8, header row): date,title,passage,memory_verse_text,memory_verse_ref,body,declaration,prayer
     * Rows are upserted by date. All rows are validated first; nothing is saved if any row fails.
     */
    public function import(): void
    {
        $this->authorizeAbility('content.manage');
        $this->validate(['importFile' => ['required', 'file', 'mimes:csv,txt', 'max:20480']]);
        $this->importErrors = [];

        $handle = fopen($this->importFile->getRealPath(), 'r');
        $header = array_map(fn ($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), fgetcsv($handle) ?: []);
        $required = ['date', 'title', 'passage', 'body'];
        if (array_diff($required, $header)) {
            $this->importErrors[] = 'Missing columns: '.implode(', ', array_diff($required, $header));

            return;
        }

        $rows = [];
        $line = 1;
        while (($cells = fgetcsv($handle)) !== false) {
            $line++;
            if (count(array_filter($cells, fn ($c) => trim((string) $c) !== '')) === 0) {
                continue;
            }
            $row = array_combine($header, array_pad(array_slice($cells, 0, count($header)), count($header), ''));
            $v = Validator::make($row, [
                'date' => ['required', 'date_format:Y-m-d', function ($attr, $value, $fail) {
                    if (! str_starts_with((string) $value, $this->year.'-')) {
                        $fail("must be in {$this->year}");
                    }
                }],
                'title' => ['required', 'string', 'max:200'],
                'passage' => ['required', 'string', 'max:120'],
                'body' => ['required', 'string'],
                'memory_verse_ref' => ['nullable', 'string', 'max:120'],
            ]);
            if ($v->fails()) {
                $this->importErrors[] = "Row {$line}: ".implode(' ', $v->errors()->all());
                if (count($this->importErrors) >= 20) {
                    break;
                }

                continue;
            }
            $rows[$row['date']] = [
                'id' => strtolower((string) Str::ulid()),
                'edition_year' => $this->year,
                'date' => $row['date'],
                'title' => trim($row['title']),
                'passage' => trim($row['passage']),
                'memory_verse_text' => trim($row['memory_verse_text'] ?? '') ?: null,
                'memory_verse_ref' => trim($row['memory_verse_ref'] ?? '') ?: null,
                'body' => str_replace("\r\n", "\n", trim($row['body'])),
                'declaration' => trim($row['declaration'] ?? '') ?: null,
                'prayer' => trim($row['prayer'] ?? '') ?: null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        fclose($handle);

        if ($this->importErrors) {
            return;
        }

        DB::transaction(fn () => collect($rows)->chunk(200)->each(fn ($chunk) => DevotionalEntry::query()->upsert(
            $chunk->values()->all(), ['date'],
            ['title', 'passage', 'memory_verse_text', 'memory_verse_ref', 'body', 'declaration', 'prayer', 'updated_at'],
        )));

        ContentPublished::dispatch('devotional', $this->year);
        $this->audit('devotional_entries.imported', DevotionalEdition::query()->find($this->year), count($rows).' entries imported');
        $this->reset('importFile', 'showImport');
        $this->toast(count($rows).' entries imported.');
    }

    public function render()
    {
        $start = Carbon::create($this->year, $this->month, 1);
        $entries = DevotionalEntry::query()->where('edition_year', $this->year)
            ->whereBetween('date', [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()])
            ->orderBy('date')->get()->keyBy(fn ($e) => $e->date->format('Y-m-d'));

        $days = collect(CarbonPeriod::create($start, $start->copy()->endOfMonth()))->map(fn ($d) => $d->toDateString());
        $monthCounts = DevotionalEntry::query()->where('edition_year', $this->year)
            ->select(DB::raw('extract(month from date)::int as m'), DB::raw('count(*) as total'))->groupBy('m')->pluck('total', 'm');

        return view('livewire.admin.content.devotionals.entries', [
            'edition' => DevotionalEdition::query()->findOrFail($this->year),
            'entries' => $entries,
            'days' => $days,
            'monthCounts' => $monthCounts,
            'today' => now(config('uec.timezone'))->toDateString(),
        ])->title("Daily Delight {$this->year}");
    }
}
