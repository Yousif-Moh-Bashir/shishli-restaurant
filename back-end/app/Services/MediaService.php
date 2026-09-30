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
    }

    public function url(string $path, string $disk = 'public'): string
    {
        $this->assertRelativePath($path);

        return Storage::disk($disk)->url($path);
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
