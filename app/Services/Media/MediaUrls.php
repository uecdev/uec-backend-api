<?php

namespace App\Services\Media;

use Illuminate\Support\Facades\Storage;
use Throwable;

class MediaUrls
{
    /**
     * Short-lived signed URL (≈15 min) for a private asset on the private disk
     * (S3 in production). Falls back to the disk's URL when signing is unsupported.
     */
    public function signedPrivateUrl(string $path): string
    {
        if (str_starts_with($path, 'https://')) {
            return $path; // externally hosted (e.g. CDN) asset
        }

        $disk = Storage::disk(config('uec.media.private_disk'));
        $expires = now()->addMinutes((int) config('uec.media.signed_url_ttl_minutes'));

        try {
            return $disk->temporaryUrl($path, $expires);
        } catch (Throwable) {
            return $disk->url($path);
        }
    }
}
