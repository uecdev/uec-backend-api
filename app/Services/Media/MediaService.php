<?php

namespace App\Services\Media;

use App\Integrations\Cloudinary\CloudinaryClient;
use App\Integrations\IntegrationException;
use App\Models\User;
use App\Support\Api\ApiException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Image and file handling. Avatars: square 512 px, EXIF/GPS stripped, Cloudinary
 * (preferred) or the public disk as a graceful fallback. Private files go to the
 * private disk (S3 in production) and are served via signed URLs.
 */
class MediaService
{
    public function __construct(private readonly CloudinaryClient $cloudinary) {}

    /** @return array{url: string, path: string} */
    public function storeAvatar(User $user, UploadedFile $file): array
    {
        $size = (int) config('uec.media.avatar_size');
        $mime = (string) $file->getMimeType();
        $isHeic = in_array($mime, ['image/heic', 'image/heif'], true);

        // Re-encode with GD whenever we can: this strips EXIF/GPS and neutralises polyglot files.
        $local = $isHeic ? $file->getRealPath() : $this->reencodeSquare($file->getRealPath(), $size);

        try {
            if ($this->cloudinary->isConfigured()) {
                $result = $this->cloudinary->uploadImage($local, 'avatars', strtolower($user->id), "c_fill,g_auto,w_{$size},h_{$size}");
                $this->deletePreviousAvatar($user, keepCloudinaryId: $result['public_id']);

                return ['url' => $this->cloudinary->deliveryUrl($result['public_id']).'?v='.time(), 'path' => 'cloudinary:'.$result['public_id']];
            }
        } catch (IntegrationException $e) {
            Log::warning('avatar.cloudinary_failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
            if ($isHeic) {
                throw new ApiException(503, __('api.service_unavailable'), 'MEDIA_UNAVAILABLE');
            }
        } finally {
            if ($local !== $file->getRealPath() && is_file($local)) {
                register_shutdown_function(fn () => @unlink($local));
            }
        }

        if ($isHeic) {
            throw ApiException::validation('avatar', 'HEIC images are not supported at the moment. Please choose a JPEG or PNG.');
        }

        $disk = Storage::disk(config('uec.media.public_disk'));
        $path = 'avatars/'.strtolower($user->id).'/'.Str::random(16).'.jpg';
        $disk->put($path, (string) file_get_contents($local), ['visibility' => 'public']);
        $this->deletePreviousAvatar($user);

        return ['url' => $disk->url($path), 'path' => $path];
    }

    public function deleteAvatar(User $user): void
    {
        $this->deletePreviousAvatar($user);
    }

    /** Store a private file (feedback attachment, hymn audio, solfa scan). Returns the disk path. */
    public function storePrivate(UploadedFile $file, string $directory): string
    {
        $ext = $file->guessExtension() ?: 'bin';
        $path = trim($directory, '/').'/'.now()->format('Y/m').'/'.Str::ulid().'.'.$ext;
        Storage::disk(config('uec.media.private_disk'))->putFileAs(dirname($path), $file, basename($path));

        return $path;
    }

    private function deletePreviousAvatar(User $user, ?string $keepCloudinaryId = null): void
    {
        $previous = $user->avatar_path;
        if (! $previous) {
            return;
        }
        if (str_starts_with($previous, 'cloudinary:')) {
            $id = substr($previous, 11);
            if ($id !== $keepCloudinaryId) {
                $this->cloudinary->destroy($id);
            }

            return;
        }
        Storage::disk(config('uec.media.public_disk'))->delete($previous);
    }

    /** Centre-crop to a square and re-encode as JPEG (drops all metadata). */
    private function reencodeSquare(string $source, int $size): string
    {
        $data = file_get_contents($source);
        $img = $data !== false ? @imagecreatefromstring($data) : false;
        if ($img === false) {
            throw ApiException::validation('avatar', __('validation.image', ['attribute' => 'avatar']));
        }

        $w = imagesx($img);
        $h = imagesy($img);
        $side = min($w, $h);
        $dst = imagecreatetruecolor($size, $size);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $img, 0, 0, (int) (($w - $side) / 2), (int) (($h - $side) / 2), $size, $size, $side, $side);

        $out = tempnam(sys_get_temp_dir(), 'avt').'.jpg';
        imagejpeg($dst, $out, 88);
        imagedestroy($img);
        imagedestroy($dst);

        return $out;
    }
}
