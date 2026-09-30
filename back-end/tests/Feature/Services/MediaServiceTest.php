<?php

namespace Tests\Feature\Services;

use App\Services\MediaService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\TestCase;

class MediaServiceTest extends TestCase
{
    public function test_storage_uses_detected_extension_unique_names_and_selected_disk(): void
    {
        Storage::fake('media', ['url' => 'https://cdn.example.test']);
        $media = new MediaService;
        $source = UploadedFile::fake()->image('photo.jpg');
        $file = new UploadedFile($source->getPathname(), 'unsafe.php', 'application/x-httpd-php', null, true);
        $first = $media->store($file, 'categories/example', 'media');
        $second = $media->store($file, 'categories/example', 'media');
        $this->assertNotSame($first, $second);
        $this->assertStringEndsWith('.jpg', $first);
        Storage::disk('media')->assertExists([$first, $second]);
        $this->assertSame('https://cdn.example.test/'.$first, $media->url($first, 'media'));
        $media->delete($first, 'media');
        $media->delete($first, 'media');
        $media->delete(null, 'media');
        $media->delete('', 'media');
        Storage::disk('media')->assertMissing($first);
        Storage::disk('media')->assertExists($second);
    }

    public function test_delete_rejects_paths_outside_the_disk(): void
    {
        Storage::fake('public');
        $this->expectException(InvalidArgumentException::class);
        (new MediaService)->delete('../outside.jpg');
    }
}
