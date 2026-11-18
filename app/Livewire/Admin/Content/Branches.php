<?php

namespace App\Livewire\Admin\Content;

use App\Livewire\Admin\Concerns\AdminComponent;
use App\Livewire\Admin\Concerns\WithDataTable;
use App\Models\Branch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('layouts.admin')]
#[Title('Branches')]
class Branches extends Component
{
    use AdminComponent, WithDataTable, WithFileUploads;

    public bool $showForm = false;

    public bool $showImport = false;

    public ?string $editingId = null;

    public string $name = '';

    public string $address = '';

    public string $city = '';

    public string $state = '';

    public string $phone = '';

    public ?string $latitude = null;

    public ?string $longitude = null;

    public bool $is_active = true;

    public $importFile = null;

    public array $importErrors = [];

    public function mount(): void
    {
        $this->authorizeAbility('content.view');
    }

    protected function sortable(): array
    {
        return ['name', 'state', 'city'];
    }

    protected function defaultSort(): string
    {
        return 'name';
    }

    public function create(): void
    {
        $this->authorizeAbility('content.manage');
        $this->resetValidation();
        $this->reset('editingId', 'name', 'address', 'city', 'state', 'phone', 'latitude', 'longitude');
        $this->is_active = true;
        $this->showForm = true;
    }

    public function edit(string $id): void
    {
        $this->authorizeAbility('content.manage');
        $this->resetValidation();
        $b = Branch::query()->findOrFail($id);
        $this->editingId = $b->id;
        foreach (['name', 'address', 'city', 'state', 'phone'] as $f) {
            $this->{$f} = (string) $b->{$f};
        }
        $this->latitude = $b->latitude !== null ? (string) $b->latitude : null;
        $this->longitude = $b->longitude !== null ? (string) $b->longitude : null;
        $this->is_active = $b->is_active;
        $this->showForm = true;
    }

    private function rulesFor(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'address' => ['required', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:30'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
        ];
    }

    public function save(): void
    {
        $this->authorizeAbility('content.manage');
        $data = $this->validate($this->rulesFor());
        $branch = $this->editingId ? Branch::query()->findOrFail($this->editingId) : new Branch;
        $branch->fill(array_map(fn ($v) => $v === '' ? null : $v, $data) + ['is_active' => $this->is_active])->save();

        $this->audit($this->editingId ? 'branch.updated' : 'branch.created', $branch, $branch->name);
        $this->showForm = false;
        $this->toast('Branch saved.');
    }

    public function delete(string $id): void
    {
        $this->authorizeAbility('content.manage');
        $branch = Branch::query()->findOrFail($id);
        $this->audit('branch.deleted', $branch, $branch->name);
        $branch->delete();
        $this->toast('Branch deleted.');
    }

    /** CSV with header: name,address,city,state,phone,latitude,longitude — appended as new branches. */
    public function import(): void
    {
        $this->authorizeAbility('content.manage');
        $this->validate(['importFile' => ['required', 'file', 'mimes:csv,txt', 'max:5120']]);
        $this->importErrors = [];

        $handle = fopen($this->importFile->getRealPath(), 'r');
        $header = array_map(fn ($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), fgetcsv($handle) ?: []);
        $rows = [];
        $line = 1;
        while (($cells = fgetcsv($handle)) !== false) {
            $line++;
            $row = array_combine($header, array_pad(array_slice($cells, 0, count($header)), count($header), ''));
            $row = array_map(fn ($v) => trim((string) $v) === '' ? null : trim((string) $v), $row);
            $v = Validator::make($row, $this->rulesFor());
            if ($v->fails()) {
                $this->importErrors[] = "Row {$line}: ".implode(' ', $v->errors()->all());

                continue;
            }
            $rows[] = array_intersect_key($row, array_flip(['name', 'address', 'city', 'state', 'phone', 'latitude', 'longitude']))
                + ['id' => strtolower((string) Str::ulid()), 'is_active' => true, 'created_at' => now(), 'updated_at' => now()];
        }
        fclose($handle);

        if ($this->importErrors) {
            return;
        }
        DB::transaction(fn () => collect($rows)->chunk(200)->each(fn ($c) => Branch::query()->insert($c->values()->all())));
        $this->audit('branches.imported', null, count($rows).' branches imported');
        $this->reset('importFile', 'showImport');
        $this->toast(count($rows).' branches imported.');
    }

    public function render()
    {
        return view('livewire.admin.content.branches', [
            'branches' => Branch::query()->search($this->search)->orderBy($this->sortColumn(), $this->sortField ? $this->sortDir() : 'asc')->paginate($this->perPage),
            'missingCoords' => Branch::query()->whereNull('latitude')->count(),
        ]);
    }
}
