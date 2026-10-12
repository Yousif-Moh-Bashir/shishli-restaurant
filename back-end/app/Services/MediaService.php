<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class MediaService
{
    public function store(UploadedFile $file, string $directory, string $disk = 'public'): string
    {
        $this->assertRelativePath($directory);
        $extension = $file->extension();

        if (! is_string($extension) || ! preg_match('/^[a-z0-9]+$/iD', $extension)) {
            throw new InvalidArgumentException('The file type could not be determined.');
        }

        $filesystem = Storage::disk($disk);

        do {
            $filename = Str::uuid().'.'.$extension;
            $path = $directory.'/'.$filename;
        } while ($filesystem->exists($path));

        try {
            if ($filesystem->putFileAs($directory, $file, $filename) === false) {
                throw new RuntimeException('Unable to store the media file.');
            }
            try {
                $this->optimize($path, $disk);
            } catch (Throwable $optimizationException) {
                // An optional derivative must not prevent an otherwise valid upload.
                report($optimizationException);
            }
        } catch (Throwable $exception) {
            try {
                $this->delete($path, $disk);
            } catch (Throwable $cleanupException) {
                report($cleanupException);
            }

            throw $exception;
        }

        return $path;
    }

    public function delete(?string $path, string $disk = 'public'): void
    {
        if ($path === null || trim($path) === '') {
            return;
        }

        $this->assertRelativePath($path);
        $filesystem = Storage::disk($disk);

        if ($filesystem->exists($path) && ! $filesystem->delete($path)) {
            throw new RuntimeException('Unable to delete the media file.');
        }
        if (config('filesystems.disks.'.$disk.'.driver') === 'local'
            && $filesystem->exists($path.'.webp') && ! $filesystem->delete($path.'.webp')) {
            throw new RuntimeException('Unable to delete the optimized media file.');
        }
    }

    public function url(string $path, string $disk = 'public'): string
    {
        $this->assertRelativePath($path);

        $filesystem = Storage::disk($disk);
        if (config('filesystems.disks.'.$disk.'.driver') === 'local' && $filesystem->exists($path.'.webp')) {
            return $filesystem->url($path.'.webp');
        }

        return $filesystem->url($path);
    }

    /**
     * Preserve original references; local raster files may have immutable WebP sidecars.
     *
     * @return array{path: string, original_bytes: int, optimized_bytes: int, width: int, height: int}|null
     */
    public function optimize(string $path, string $disk = 'public'): ?array
    {
        $this->assertRelativePath($path);
        if (config('filesystems.disks.'.$disk.'.driver') !== 'local' || ! function_exists('imagewebp')
            || ! preg_match('/\.(png|jpe?g|webp)$/iD', $path)) {
            return null;
        }
        $filesystem = Storage::disk($disk);
        if (! $filesystem->exists($path)) {
            return null;
        }
        $size = $filesystem->size($path);
        if ($size < 262144 || $size > 10485760) {
            return null;
        }
        $sourcePath = $filesystem->path($path);
        $dimensions = @getimagesize($sourcePath);
        if ($dimensions === false || $dimensions[0] * $dimensions[1] > 8000000) {
            return null;
        }
        // GD does not apply EXIF orientation; keep oriented JPEG originals intact.
        if ($dimensions[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $exif = @exif_read_data($sourcePath);
            if (is_array($exif) && (int) ($exif['Orientation'] ?? 1) !== 1) {
                return null;
            }
        }
        $variant = $path.'.webp';
        if ($filesystem->exists($variant)) {
            $existing = @getimagesize($filesystem->path($variant));

            return $existing === false ? null : ['path' => $variant, 'original_bytes' => $size, 'optimized_bytes' => $filesystem->size($variant), 'width' => $existing[0], 'height' => $existing[1]];
        }
        $contents = $filesystem->get($path);
        // GD derivatives are static. Preserve animated PNG/WebP uploads instead of losing frames.
        if (($dimensions[2] === IMAGETYPE_PNG && str_contains($contents, 'acTL'))
            || ($dimensions[2] === IMAGETYPE_WEBP && str_contains($contents, 'ANIM'))) {
            return null;
        }
        $source = @imagecreatefromstring($contents);
        if ($source === false) {
            return null;
        }
        $scale = min(1, 1600 / max($dimensions[0], $dimensions[1]));
        $width = max(1, (int) round($dimensions[0] * $scale));
        $height = max(1, (int) round($dimensions[1] * $scale));
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagecopyresampled($image, $source, 0, 0, 0, 0, $width, $height, $dimensions[0], $dimensions[1]);
        ob_start();
        try {
            $encoded = imagewebp($image, null, 84);
            $bytes = ob_get_contents();
        } finally {
            ob_end_clean();
            imagedestroy($image);
            imagedestroy($source);
        }
        if (! $encoded || ! is_string($bytes) || $bytes === '' || strlen($bytes) >= $size) {
            return null;
        }
        $temporary = $variant.'.'.Str::uuid().'.tmp';
        try {
            if (! $filesystem->put($temporary, $bytes) || ! $filesystem->move($temporary, $variant)) {
                throw new RuntimeException('Unable to store the optimized media file.');
            }
        } finally {
            $filesystem->delete($temporary);
        }

        return ['path' => $variant, 'original_bytes' => $size, 'optimized_bytes' => strlen($bytes), 'width' => $width, 'height' => $height];
    }

    private function assertRelativePath(string $path): void
    {
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '\\')
            || str_contains($path, ':') || preg_match('/[\x00-\x1F]/', $path)
            || in_array('..', explode('/', $path), true)) {
            throw new InvalidArgumentException('Media paths must be relative to the storage disk.');
        }
    }
}
