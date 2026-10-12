<?php

namespace App\Console\Commands;

use App\Services\MediaService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

#[Signature('media:optimize {--disk=public : Local media disk} {--directory=products : Relative media directory}')]
#[Description('Create smaller WebP sidecars while retaining original media and database references')]
class OptimizeMedia extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(MediaService $media): int
    {
        $disk = (string) $this->option('disk');
        $directory = (string) $this->option('directory');
        if (config('filesystems.disks.'.$disk.'.driver') !== 'local' || $directory === ''
            || str_starts_with($directory, '/') || str_contains($directory, '\\') || str_contains($directory, ':')
            || in_array('..', explode('/', $directory), true)) {
            $this->error('A local disk and a safe relative directory are required.');

            return self::FAILURE;
        }
        $results = [];
        foreach (Storage::disk($disk)->allFiles($directory) as $path) {
            if (str_ends_with($path, '.webp')) {
                continue;
            }
            if (($result = $media->optimize($path, $disk)) !== null) {
                $results[] = $result;
            }
        }
        $this->line(json_encode($results, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
