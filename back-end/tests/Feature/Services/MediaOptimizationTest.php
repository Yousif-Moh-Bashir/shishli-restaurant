<?php

namespace Tests\Feature\Services;

use App\Http\Resources\Api\V1\ProductImageResource;
use App\Models\ProductImage;
use App\Services\MediaService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaOptimizationTest extends TestCase
{
    public function test_large_upload_retains_original_and_serves_smaller_alpha_preserving_variant(): void
    {
        Storage::fake('public');
        $file = $this->photograph();
        $media = new MediaService;
        $path = $media->store($file, 'products/example');
        $filesystem = Storage::disk('public');
        $filesystem->assertExists([$path, $path.'.webp']);
        $this->assertStringEndsWith('.png', $path);
        $this->assertLessThan($filesystem->size($path), $filesystem->size($path.'.webp'));
        $image = imagecreatefromwebp($filesystem->path($path.'.webp'));
        $this->assertSame(1600, imagesx($image));
        $this->assertSame(800, imagesy($image));
        $this->assertSame(127, imagecolorsforindex($image, imagecolorat($image, 0, 0))['alpha']);
        imagedestroy($image);
        $model = new ProductImage;
        $model->forceFill(['uuid' => 'image-test', 'disk' => 'public', 'path' => $path, 'is_primary' => true]);
        $resource = (new ProductImageResource($model))->resolve();
        $this->assertSame($filesystem->url($path.'.webp'), $resource['url']);
        $this->assertSame(['id', 'url', 'alt_text', 'sort_order', 'is_primary'], array_keys($resource));
        $this->assertSame($path, $model->path);
        $this->assertSame($media->optimize($path), $media->optimize($path));
        $media->delete($path);
        $filesystem->assertMissing([$path, $path.'.webp']);
        $media->delete($path);
    }

    public function test_missing_variant_falls_back_and_cloud_disks_do_not_probe_for_derivatives(): void
    {
        Storage::fake('public');
        $media = new MediaService;
        $path = $media->store(UploadedFile::fake()->image('small.png'), 'products/example');
        $this->assertSame(Storage::disk('public')->url($path), $media->url($path));
        $this->assertNull($media->optimize($path));
        Storage::disk('public')->put($path.'.webp', 'unused cloud sidecar');
        config(['filesystems.disks.public.driver' => 's3']);
        $this->assertNull($media->optimize($path));
        $this->assertSame(Storage::disk('public')->url($path), $media->url($path));
    }

    public function test_optional_optimizer_failure_does_not_discard_valid_upload(): void
    {
        Storage::fake('public');
        $media = new class extends MediaService
        {
            public function optimize(string $path, string $disk = 'public'): ?array
            {
                throw new \RuntimeException('Simulated optional encoding failure');
            }
        };
        $path = $media->store(UploadedFile::fake()->image('photo.png'), 'products/example');
        Storage::disk('public')->assertExists($path);
        $this->assertSame(Storage::disk('public')->url($path), $media->url($path));
    }

    public function test_command_only_creates_variants_inside_safe_directory(): void
    {
        Storage::fake('public');
        $file = $this->photograph();
        Storage::disk('public')->put('products/sample.png', file_get_contents($file->getPathname()));
        $this->artisan('media:optimize', ['--directory' => '../outside'])->assertFailed();
        $this->artisan('media:optimize', ['--directory' => 'products'])->assertSuccessful();
        Storage::disk('public')->assertExists(['products/sample.png', 'products/sample.png.webp']);
        $this->assertCount(2, Storage::disk('public')->allFiles('products'));
    }

    private function photograph(): UploadedFile
    {
        $file = UploadedFile::fake()->image('photograph.png');
        $image = imagecreatetruecolor(1800, 900);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        $color = 42;
        for ($y = 0; $y < 900; $y++) {
            for ($x = 0; $x < 1800; $x++) {
                $color = ($color * 1103515245 + 12345) & 0xFFFFFF;
                imagesetpixel($image, $x, $y, $color);
            }
        }
        imagefilledrectangle($image, 0, 0, 40, 40, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagepng($image, $file->getPathname());
        imagedestroy($image);

        return $file;
    }
}
