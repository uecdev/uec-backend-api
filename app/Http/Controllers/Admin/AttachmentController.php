<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FeedbackAttachment;
use App\Services\Media\MediaUrls;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class AttachmentController extends Controller
{
    /** Redirect to a short-lived signed URL (S3) or stream from the local private disk. */
    public function feedback(FeedbackAttachment $attachment, MediaUrls $urls): Response
    {
        Gate::authorize('feedback.view');

        if ($attachment->disk === 's3') {
            return redirect()->away($urls->signedPrivateUrl($attachment->path));
        }

        return Storage::disk($attachment->disk)->response($attachment->path, $attachment->original_name, [
            'Content-Type' => $attachment->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'",
        ], $attachment->mime === 'application/pdf' ? 'inline' : 'inline');
    }
}
