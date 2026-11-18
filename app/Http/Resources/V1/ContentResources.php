<?php

namespace App\Http\Resources\V1;

use App\Models\Branch;
use App\Models\DevotionalEdition;
use App\Models\DevotionalEntry;
use App\Models\Event;
use App\Models\Page;
use App\Models\SsLesson;
use App\Models\SsManual;
use App\Models\SsPlanWeek;
use App\Support\Iso;

/**
 * Payload builders for read-only content (backend-handoff C.4).
 */
final class ContentResources
{
    public static function edition(DevotionalEdition $e, ?int $entryCount = null): array
    {
        return [
            'year' => $e->year,
            'title' => $e->title,
            'theme' => $e->theme,
            'entry_count' => $entryCount ?? (int) ($e->entries_count ?? $e->entries()->count()),
            'size_bytes' => (int) $e->bundle_size,
            'published_at' => Iso::ts($e->published_at),
            'is_premium' => (bool) $e->is_premium,
        ];
    }

    public static function entrySummary(DevotionalEntry $e): array
    {
        return [
            'date' => Iso::date($e->date),
            'title' => $e->title,
            'passage' => $e->passage,
        ];
    }

    public static function entry(DevotionalEntry $e): array
    {
        return [
            'date' => Iso::date($e->date),
            'edition_year' => $e->edition_year,
            'title' => $e->title,
            'passage' => $e->passage,
            'memory_verse' => ['text' => (string) $e->memory_verse_text, 'reference' => (string) $e->memory_verse_ref],
            'body' => $e->body,
            'declaration' => $e->declaration,
            'prayer' => $e->prayer,
        ];
    }

    public static function manual(SsManual $m, ?int $lessonCount = null): array
    {
        return [
            'year' => $m->year,
            'title' => $m->title,
            'theme' => $m->theme,
            'lesson_count' => $lessonCount ?? (int) ($m->lessons_count ?? $m->lessons()->count()),
            'size_bytes' => (int) $m->bundle_size,
            'published_at' => Iso::ts($m->published_at),
            'is_premium' => (bool) $m->is_premium,
        ];
    }

    public static function lessonSummary(SsLesson $l): array
    {
        return [
            'id' => $l->id,
            'manual_year' => $l->manual_year,
            'number' => $l->number,
            'title' => $l->title,
            'passage' => $l->passage,
        ];
    }

    public static function lesson(SsLesson $l): array
    {
        return self::lessonSummary($l) + [
            'memory_verse' => ['text' => (string) $l->memory_verse_text, 'reference' => (string) $l->memory_verse_ref],
            'aim' => (string) $l->aim,
            'introduction' => (string) $l->introduction,
            'outline' => array_values(array_map(fn ($o) => [
                'heading' => (string) ($o['heading'] ?? ''),
                'body' => (string) ($o['body'] ?? ''),
            ], $l->outline ?? [])),
            'applications' => array_values($l->applications ?? []),
            'conclusion' => (string) $l->conclusion,
            'revision_questions' => array_values($l->revision_questions ?? []),
        ];
    }

    public static function planWeek(SsPlanWeek $w): array
    {
        return [
            'date' => Iso::date($w->date),
            'week' => $w->week,
            'lesson_id' => $w->lesson_id,
            'lesson_number' => $w->lesson?->number,
            'description' => $w->description,
        ];
    }

    public static function event(Event $e): array
    {
        return [
            'id' => $e->id,
            'title' => $e->title,
            'start_date' => Iso::date($e->start_date),
            'end_date' => Iso::date($e->end_date),
            'start_time' => $e->start_time,
            'description' => (string) $e->description,
            'location' => $e->location,
            'category' => $e->category,
            'share_url' => $e->share_url,
        ];
    }

    public static function branch(Branch $b): array
    {
        return [
            'id' => $b->id,
            'name' => $b->name,
            'address' => $b->address,
            'city' => $b->city,
            'state' => $b->state,
            'phone' => $b->phone,
            'latitude' => $b->latitude,
            'longitude' => $b->longitude,
        ];
    }

    public static function page(Page $p): array
    {
        return [
            'slug' => $p->slug,
            'title' => $p->title,
            'body' => $p->body,
            'updated_at' => Iso::ts($p->updated_at),
        ];
    }
}
