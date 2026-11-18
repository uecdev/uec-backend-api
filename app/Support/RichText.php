<?php

namespace App\Support;

/**
 * The restricted content markup used by the mobile app (backend-handoff C.2):
 *   blank line = paragraph · **bold** · *italic* · [label](bible:Ref) · [label](https://…)
 * The app never renders HTML, so admin content is stored as this markup. This class
 * renders an escaped HTML preview for editors and flags constructs the app ignores.
 */
final class RichText
{
    public static function toHtml(?string $text): string
    {
        $text = trim((string) $text);
        if ($text === '') {
            return '';
        }

        $paragraphs = preg_split('/\R{2,}/', $text) ?: [];

        return implode('', array_map(function (string $p) {
            $html = e($p);
            $html = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $html) ?? $html;
            $html = preg_replace('/(?<![*\w])\*(?!\s)(.+?)(?<!\s)\*(?!\w)/s', '<em>$1</em>', $html) ?? $html;
            $html = preg_replace('/\[([^\]]+)\]\(bible:([^)]+)\)/', '<span class="scripture" title="$2">$1</span>', $html) ?? $html;
            $html = preg_replace('/\[([^\]]+)\]\((https:\/\/[^)\s]+)\)/', '<a href="$2" target="_blank" rel="noopener noreferrer" class="underline">$1</a>', $html) ?? $html;

            return '<p>'.nl2br($html, false).'</p>';
        }, $paragraphs));
    }

    /** @return list<string> human-readable warnings about markup the app will show literally */
    public static function warnings(?string $text): array
    {
        $text = (string) $text;
        $warnings = [];
        if (preg_match('/<\/?[a-z][^>]*>/i', $text)) {
            $warnings[] = 'HTML tags are shown as literal text in the app. Use **bold**, *italic* and [label](link) instead.';
        }
        if (preg_match('/\[[^\]]+\]\(http:\/\//i', $text)) {
            $warnings[] = 'Links must use https:// — http links are ignored by the app.';
        }

        return $warnings;
    }
}
