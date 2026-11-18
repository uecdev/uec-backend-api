<?php

namespace App\Livewire\Admin\Concerns;

use Livewire\Attributes\Url;
use Livewire\WithPagination;

/**
 * Search, sort and pagination state for admin tables, kept in the URL so views
 * are shareable and survive refreshes. Components list allowed sort columns.
 */
trait WithDataTable
{
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $sortField = '';

    #[Url(except: 'desc')]
    public string $sortDirection = 'desc';

    public int $perPage = 25;

    /** @return list<string> */
    abstract protected function sortable(): array;

    abstract protected function defaultSort(): string;

    public function sortBy(string $field): void
    {
        if (! in_array($field, $this->sortable(), true)) {
            return;
        }
        $this->sortDirection = $this->sortField === $field && $this->sortDirection === 'asc' ? 'desc' : 'asc';
        $this->sortField = $field;
        $this->resetPage();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updated(string $property): void
    {
        if (str_starts_with($property, 'filter')) {
            $this->resetPage();
        }
    }

    protected function sortColumn(): string
    {
        return in_array($this->sortField, $this->sortable(), true) ? $this->sortField : $this->defaultSort();
    }

    protected function sortDir(): string
    {
        return $this->sortDirection === 'asc' ? 'asc' : 'desc';
    }
}
