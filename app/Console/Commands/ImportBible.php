<?php

namespace App\Console\Commands;

use App\Models\BibleVersion;
use App\Support\Usfm;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Imports a Bible translation into bible_verses (backend-handoff B.6: KJV is public domain;
 * other versions need a licence). Accepted formats:
 *
 *  1. JSON rows:   [{"book": "GEN", "chapter": 1, "verse": 1, "text": "In the beginning…"}, …]
 *  2. JSON books in canonical order (66 books), each {"chapters": [["v1", "v2", …], …]}
 *     — the widely used public-domain KJV JSON layout
 *  3. CSV with a header row: book,chapter,verse,text   (book = USFM code)
 */
#[Signature('uec:bible:import {version : Version id, e.g. KJV} {file : Path to the JSON or CSV file} {--name= : Full name, e.g. "King James Version"} {--licence= : Licence note} {--replace : Delete existing verses for this version first}')]
#[Description('Import a Bible translation from JSON or CSV')]
class ImportBible extends Command
{
    public function handle(): int
    {
        $versionId = strtoupper((string) $this->argument('version'));
        $file = (string) $this->argument('file');
        if (! is_readable($file)) {
            $this->error("Cannot read {$file}");

            return self::FAILURE;
        }

        $rows = str_ends_with(strtolower($file), '.csv') ? $this->fromCsv($file) : $this->fromJson($file);
        if ($rows === []) {
            $this->error('No verses found. Check the file format (see command help).');

            return self::FAILURE;
        }

        $invalid = array_filter($rows, fn ($r) => ! Usfm::isValid($r['book_id'], $r['chapter']) || $r['verse'] < 1 || trim($r['text']) === '');
        if ($invalid) {
            $this->error(count($invalid).' rows have an invalid book, chapter, verse or empty text. Nothing imported.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($versionId, $rows) {
            BibleVersion::query()->updateOrCreate(['id' => $versionId], array_filter([
                'name' => $this->option('name') ?: ($versionId === 'KJV' ? 'King James Version' : $versionId),
                'abbreviation' => $versionId,
                'licence_note' => $this->option('licence') ?: ($versionId === 'KJV' ? 'Public domain' : null),
                'is_active' => true,
            ], fn ($v) => $v !== null));

            if ($this->option('replace')) {
                DB::table('bible_verses')->where('version_id', $versionId)->delete();
            }

            $bar = $this->output->createProgressBar(count($rows));
            foreach (array_chunk($rows, 1000) as $chunk) {
                DB::table('bible_verses')->upsert(
                    array_map(fn ($r) => $r + ['version_id' => $versionId], $chunk),
                    ['version_id', 'book_id', 'chapter', 'verse'],
                    ['text'],
                );
                $bar->advance(count($chunk));
            }
            $bar->finish();
            $this->newLine();
        });

        // Chapter payloads are cached for a week; bumping the revision invalidates them all.
        Cache::forever('bible:rev', (int) Cache::get('bible:rev', 1) + 1);
        $this->info(sprintf('Imported %s verses into %s.', number_format(count($rows)), $versionId));

        return self::SUCCESS;
    }

    /** @return list<array{book_id: string, chapter: int, verse: int, text: string}> */
    private function fromJson(string $file): array
    {
        $raw = (string) file_get_contents($file);
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw; // strip UTF-8 BOM
        $data = json_decode($raw, true);
        if (! is_array($data) || $data === []) {
            return [];
        }

        // Format 1: flat rows
        if (isset($data[0]['chapter'], $data[0]['verse'])) {
            return array_map(fn ($r) => [
                'book_id' => strtoupper((string) ($r['book'] ?? $r['book_id'] ?? '')),
                'chapter' => (int) $r['chapter'],
                'verse' => (int) $r['verse'],
                'text' => trim((string) $r['text']),
            ], $data);
        }

        // Format 2: books in canonical order with nested chapters
        $rows = [];
        foreach (array_values($data) as $bookIndex => $book) {
            $code = Usfm::codeAt($bookIndex);
            if (! $code || ! isset($book['chapters'])) {
                return [];
            }
            foreach ($book['chapters'] as $c => $verses) {
                foreach ($verses as $v => $text) {
                    $rows[] = ['book_id' => $code, 'chapter' => $c + 1, 'verse' => $v + 1, 'text' => trim((string) $text)];
                }
            }
        }

        return $rows;
    }

    private function fromCsv(string $file): array
    {
        $handle = fopen($file, 'r');
        $header = array_map(fn ($h) => strtolower(trim((string) $h)), fgetcsv($handle) ?: []);
        $rows = [];
        while (($line = fgetcsv($handle)) !== false) {
            $r = array_combine($header, array_pad($line, count($header), ''));
            $rows[] = ['book_id' => strtoupper(trim($r['book'] ?? '')), 'chapter' => (int) ($r['chapter'] ?? 0), 'verse' => (int) ($r['verse'] ?? 0), 'text' => trim($r['text'] ?? '')];
        }
        fclose($handle);

        return $rows;
    }
}
