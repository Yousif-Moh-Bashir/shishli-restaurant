<?php

namespace App\Services;

use App\Models\Banner;
use App\Models\GalleryItem;
use App\Models\PageSection;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class CmsWriteService
{
    public function __construct(private MediaService $media) {}

    public function save(Model $model, array $data, User $actor): Model
    {
        $new = [];
        $old = [];
        $disk = config('filesystems.media_disk', 'public');
        $model->uuid ??= (string) Str::uuid();
        try {
            return DB::transaction(function () use ($model, $data, $actor, $disk, &$new, &$old): Model {
                if ($model->exists) {
                    $model = $model->newQuery()->whereKey($model->getKey())->lockForUpdate()->firstOrFail();
                }
                foreach (['published_at', 'starts_at', 'ends_at'] as $dateField) {
                    if (isset($data[$dateField])) {
                        $data[$dateField] = CarbonImmutable::parse($data[$dateField])->utc();
                    }
                }
                foreach (['image', 'mobile_image', 'og_image'] as $field) {
                    if (! array_key_exists($field, $data)) {
                        continue;
                    }
                    if ($model instanceof PageSection) {
                        $previous = $model->data['image_path'] ?? null;
                        $path = null;
                        if ($data[$field] instanceof UploadedFile) {
                            $new[] = $path = $this->media->store($data[$field], 'cms/pages/'.$model->page->uuid, $disk);
                        }
                        if ($previous !== null && $previous !== $path) {
                            $old[] = $previous;
                        }
                        $sectionData = $data['data'] ?? $model->data ?? [];
                        unset($sectionData['image_path']);
                        if ($path !== null) {
                            $sectionData['image_path'] = $path;
                        }
                        $data['data'] = $sectionData;
                        unset($data[$field]);

                        continue;
                    }
                    if ($data[$field] instanceof UploadedFile) {
                        $directory = match (true) {
                            $model instanceof Banner => 'cms/banners/'.$model->uuid,$model instanceof GalleryItem => 'cms/gallery/'.$model->uuid,default => 'cms/pages/'.$model->uuid
                        };
                        $new[] = $data[$field] = $this->media->store($data[$field], $directory, $disk);
                    }
                    if ($model->getAttribute($field) !== null && $model->getAttribute($field) !== $data[$field]) {
                        $old[] = $model->getAttribute($field);
                    }
                }
                if ($model instanceof Banner) {
                    $start = array_key_exists('starts_at', $data) ? $data['starts_at'] : $model->starts_at;
                    $end = array_key_exists('ends_at', $data) ? $data['ends_at'] : $model->ends_at;
                    if ($start !== null && $end !== null && CarbonImmutable::parse($end)->lessThanOrEqualTo(CarbonImmutable::parse($start))) {
                        throw ValidationException::withMessages(['ends_at' => 'The end must be after the start.']);
                    }
                }
                if ($model instanceof PageSection) {
                    if (array_key_exists('data', $data) && ! array_key_exists('image_path', $data['data'] ?? []) && ! array_key_exists('image', $data) && ($model->data['image_path'] ?? null) !== null && ! in_array($model->data['image_path'], $old, true)) {
                        $data['data'] = array_merge($data['data'] ?? [], ['image_path' => $model->data['image_path']]);
                    }
                    $model->page()->update(['updated_by' => $actor->id]);
                    app(CmsContentService::class)->invalidate($model);
                } else {
                    $data['updated_by'] = $actor->id;
                    if (! $model->exists) {
                        $data['created_by'] = $actor->id;
                    }
                }
                $model->fill($data)->save();
                DB::afterCommit(fn () => $this->cleanup($old, $disk));

                return $model->refresh();
            });
        } catch (Throwable $exception) {
            $this->cleanup($new, $disk);
            throw $exception;
        }
    }

    public function delete(Banner|GalleryItem $model): void
    {
        DB::transaction(function () use ($model): void {
            $model = $model->newQuery()->whereKey($model->getKey())->lockForUpdate()->firstOrFail();
            $paths = [$model->image];
            if ($model instanceof Banner) {
                $paths[] = $model->mobile_image;
            }
            $model->delete();
            DB::afterCommit(fn () => $this->cleanup($paths, config('filesystems.media_disk', 'public')));
        });
    }

    public function reorder(string $class, array $items, User $actor): void
    {
        DB::transaction(function () use ($class, $items, $actor): void {
            $models = $class::whereIn('uuid', array_column($items, 'id'))->orderBy('id')->lockForUpdate()->get()->keyBy('uuid');
            if ($models->count() !== count($items)) {
                throw ValidationException::withMessages(['items' => 'Some items no longer exist.']);
            }
            foreach ($items as $item) {
                $models[$item['id']]->update(['sort_order' => $item['sort_order'], 'updated_by' => $actor->id]);
            }
        });
    }

    private function cleanup(array $paths, string $disk): void
    {
        foreach ($paths as $path) {
            try {
                $this->media->delete($path, $disk);
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }
}
