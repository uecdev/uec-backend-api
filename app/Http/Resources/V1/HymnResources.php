<?php

namespace App\Http\Resources\V1;

use App\Models\Hymn;
use App\Models\HymnAudio;
use App\Models\HymnSolfa;
use App\Services\Media\MediaUrls;
use App\Support\Iso;

/**
 * Hymn payloads (backend-handoff C.4 #21/#22). Static builders keep the catalogue
 * (≈1 KB per 10 hymns, unpaginated) cheap to serialise.
 */
final class HymnResources
{
    public static function summary(Hymn $hymn): array
    {
        return [
            'id' => $hymn->id,
            'number' => $hymn->number,
            'title' => $hymn->title,
            'first_line' => $hymn->first_line,
            'categories' => $hymn->categories->pluck('slug')->values()->all(),
            'has_audio' => $hymn->relationLoaded('audio') ? $hymn->audio->isNotEmpty() : (bool) ($hymn->audio_count ?? false),
            'has_solfa' => $hymn->relationLoaded('solfa') ? $hymn->solfa !== null : (bool) ($hymn->solfa_exists ?? false),
        ];
    }

    /**
     * Server-side entitlement enforcement (E.6): audio URLs are short-lived signed URLs
     * returned only with hymn.audio; solfa only with hymn.solfa. Non-entitled users get
     * audio: [] and solfa: null, while has_audio/has_solfa still drive the paywall.
     */
    public static function detail(Hymn $hymn, bool $audioEntitled, bool $solfaEntitled): array
    {
        $urls = app(MediaUrls::class);

        return self::summary($hymn) + [
            'stanzas' => $hymn->stanzas->map(fn ($s) => [
                'type' => $s->type,
                'number' => $s->number,
                'lines' => array_values($s->lines ?? []),
            ])->values()->all(),
            'solfa' => $solfaEntitled && $hymn->solfa ? self::solfa($hymn->solfa, $urls) : null,
            'audio' => $audioEntitled ? $hymn->audio->map(fn (HymnAudio $a) => [
                'id' => $a->id,
                'label' => $a->label,
                'url' => $urls->signedPrivateUrl($a->storage_path),
                'duration_seconds' => $a->duration_seconds,
                'size_bytes' => $a->size_bytes,
            ])->values()->all() : [],
            'author' => $hymn->author,
            'composer' => $hymn->composer,
            'tune' => $hymn->tune,
            'meter' => $hymn->meter,
            'key' => $hymn->key,
            'tempo' => $hymn->tempo,
            'about' => $hymn->about,
            'scripture_refs' => array_values($hymn->scripture_refs ?? []),
            'tags' => array_values($hymn->tags ?? []),
            'updated_at' => Iso::ts($hymn->updated_at),
        ];
    }

    private static function solfa(HymnSolfa $solfa, MediaUrls $urls): array
    {
        if ($solfa->format === 'image') {
            return [
                'format' => 'image',
                'image_url' => $urls->signedPrivateUrl((string) $solfa->image_path),
                'width' => $solfa->width,
                'height' => $solfa->height,
            ];
        }

        return [
            'format' => 'text',
            'key' => $solfa->key,
            'time_signature' => $solfa->time_signature,
            'sections' => array_values(array_map(fn ($s) => [
                'label' => (string) ($s['label'] ?? ''),
                'lines' => array_values((array) ($s['lines'] ?? [])),
            ], $solfa->sections ?? [])),
        ];
    }
}
