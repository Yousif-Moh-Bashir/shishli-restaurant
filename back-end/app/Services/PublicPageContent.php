<?php

namespace App\Services;

use App\Models\Banner;
use App\Models\Branch;
use App\Models\Category;
use App\Models\GalleryItem;
use App\Models\Page;
use App\Models\Product;
use App\Models\Promotion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class PublicPageContent
{
    public const CMS = ['about', 'contact', 'gallery'];

    public const STATIC_PATHS = ['/', '/menu', '/branches', '/offers', '/grills-tabuk'];

    private const GRILL_CATEGORIES = ['allhom', 'aldgag', 'mshkl', 'grills', 'mixed-grills', 'meat', 'shish', 'turkish-chicken'];

    private const HOME_TITLE = 'مطعم مشويات في تبوك | شيشلي فرش';

    private const HOME_DESCRIPTION = 'شيشلي فرش مطعم مشويات وكباب في تبوك. تصفح الدجاج التركي على الفحم والشيش وأطباق المشكل، وتحقق من خيارات الاستلام والتوصيل عند الطلب.';

    private const LABELS = [
        '/' => 'الرئيسية', '/menu' => 'قائمة الطعام', '/branches' => 'فروعنا', '/offers' => 'العروض',
        '/about' => 'من نحن', '/contact' => 'تواصل معنا', '/gallery' => 'معرض الصور',
        '/grills-tabuk' => 'مشويات شيشلي في تبوك',
    ];

    public function __construct(private CmsSectionDefinition $definitions, private MediaService $media, private RestaurantSettingsService $settings) {}

    public static function categories(): Builder
    {
        return Category::active()->whereHas('products', fn (Builder $query): Builder => $query->visibleInMenu())
            ->orderBy('sort_order')->orderBy('id');
    }

    public static function accepts(string $path): bool
    {
        $path = strtolower(rtrim($path, '/')) ?: '/';

        return in_array($path, self::STATIC_PATHS, true) || in_array(ltrim($path, '/'), self::CMS, true)
            || preg_match('~^/menu/category/[a-z0-9]+(?:-[a-z0-9]+)*$~D', $path) === 1
            || $path === '/product' || str_starts_with($path, '/product/');
    }

    public function isPublic(string $path): bool
    {
        if (in_array($path, self::STATIC_PATHS, true)) {
            return true;
        }

        if (! self::accepts($path)) {
            return false;
        }

        if (str_starts_with($path, '/product')) {
            $uuid = Str::after($path, '/product/');

            return Str::isUuid($uuid) && Product::visibleInMenu()->where('uuid', $uuid)->exists();
        }

        if (str_starts_with($path, '/menu/category/')) {
            return self::categories()->where('slug', Str::afterLast($path, '/'))->exists();
        }

        return Page::where('key', ltrim($path, '/'))->first()?->isPublished() ?? false;
    }

    public function get(string $path): ?array
    {
        $path = strtolower(rtrim($path, '/')) ?: '/';
        if (! self::accepts($path)) {
            return null;
        }
        $published = Page::whereIn('key', self::CMS)->get()->filter(fn (Page $page): bool => $page->isPublished())->keyBy('key');
        $nav = collect(self::LABELS)->filter(fn (string $label, string $key): bool => $key !== $path
            && (in_array($key, self::STATIC_PATHS, true) || $published->has(ltrim($key, '/'))))
            ->map(fn (string $label, string $key): array => ['label' => $label, 'href' => $key])->values()->all();
        $brand = 'مطعم شيشلي فرش';
        $page = ['path' => $path, 'title' => '', 'description' => '', 'heading' => self::LABELS[$path] ?? '', 'intro' => '', 'image' => null, 'sections' => [], 'items' => [], 'links' => $nav, 'schema' => null];
        if (str_starts_with($path, '/product')) {
            $uuid = substr($path, strlen('/product/'));
            if (! Str::isUuid($uuid)) {
                return null;
            }
            $product = Product::visibleInMenu()->where('uuid', $uuid)->with(['category', 'primaryImage', 'images'])->first();
            if ($product === null) {
                return null;
            }
            $image = $product->primaryImage ?? $product->images->first();
            $page['image'] = $image ? $this->image($image->path, $image->disk) : null;
            $page['heading'] = $product->name;
            $page['intro'] = $product->short_description ?: ($product->description ?: '');
            $page['links'][] = ['label' => $product->category->name, 'href' => '/menu/category/'.$product->category->slug];
            $page['schema'] = ['@context' => 'https://schema.org', ...$this->menuItem($product->name, $path, $page['intro'], $page['image'])];
            if ($product->short_description && $product->description && $product->description !== $product->short_description) {
                $page['sections'][] = ['title' => 'تفاصيل المنتج', 'text' => $product->description];
            }
        } elseif ($path === '/') {
            $home = Page::where('key', 'home')->with('sections')->first();
            $page['heading'] = $brand.' للمشويات والكباب في تبوك';
            $page['intro'] = self::HOME_DESCRIPTION;
            $page['title'] = self::HOME_TITLE;
            $page['description'] = self::HOME_DESCRIPTION;
            if ($home?->isPublished()) {
                $page['sections'] = $this->sections($home);
                $banner = Banner::where('placement', 'home_hero')->orderBy('sort_order')->orderBy('id')->get()->first(fn (Banner $banner) => $banner->isPublished());
                if ($banner?->title || $banner?->subtitle) {
                    array_unshift($page['sections'], ['title' => $banner->title ?? '', 'text' => $banner->subtitle ?? '']);
                }
            }
            $page['items'] = $this->products(featured: true);
            $page['schema'] = $this->restaurant();
        } elseif ($path === '/menu') {
            $page['heading'] = 'اختر وجبتك… على ذوقك';
            $page['title'] = 'قائمة الطعام | '.$brand;
            $page['intro'] = 'دجاج تركي، شيش ومشويات على الفحم. تصفح الأطباق واختر الفرع للطلب.';
            $page['items'] = $this->products();
            $page['links'] = [...$page['links'], ...$this->categoryLinks(self::categories()->get(['slug', 'name']))];
        } elseif ($path === '/grills-tabuk' || str_starts_with($path, '/menu/category/')) {
            $grills = $path === '/grills-tabuk';
            $categories = self::categories();
            if ($grills) {
                $selected = $categories->whereIn('slug', self::GRILL_CATEGORIES)->get(['id', 'slug', 'name']);
                $page['heading'] = 'مشويات وكباب في تبوك';
                $page['title'] = 'مشاوي وكباب في تبوك | شيشلي فرش';
                $page['intro'] = 'تصفح أصناف المشويات المنشورة من شيشلي فرش، من الكباب والشيش إلى الدجاج التركي وأطباق المشكل.';
                $page['sections'] = $this->delivery();
                $page['links'] = [...$page['links'], ...$this->categoryLinks($selected)];
            } else {
                $selected = $categories->where('slug', Str::afterLast($path, '/'))->get(['id', 'slug', 'name']);
                if ($selected->isEmpty()) {
                    return null;
                }
                $page['heading'] = $selected->first()->name;
                $page['title'] = $page['heading'].' | منيو شيشلي فرش';
                $page['intro'] = 'تصفح أطباق '.$page['heading'].' في منيو شيشلي فرش. افتح صفحة الطبق للتفاصيل واختر الفرع للسعر والطلب.';
            }
            $page['items'] = $this->products(categoryIds: $selected->modelKeys(), withImages: true);
        } elseif ($path === '/branches') {
            $page['heading'] = 'فروع شيشلي فرش';
            $page['intro'] = 'تعرّف على فروع شيشلي فرش ومواقعها.';
            $page['sections'] = Branch::where('is_active', true)->orderBy('sort_order')->orderBy('id')->get()
                ->map(fn (Branch $branch): array => ['title' => $branch->name, 'text' => implode('، ', array_filter([$branch->city, $branch->district, $branch->address]))])->all();
        } elseif ($path === '/offers') {
            $page['heading'] = 'عروض شيشلي فرش';
            $page['intro'] = 'استعرض عروض مطعم شيشلي فرش الحالية.';
            $page['sections'] = Promotion::publicOffers()->orderByDesc('priority')->orderBy('uuid')->limit(20)->get()
                ->map(fn (Promotion $promotion): array => ['title' => $promotion->name, 'text' => $promotion->description ?? ''])->all();
        } else {
            $model = $published->get(ltrim($path, '/'));
            if ($model === null) {
                return null;
            }
            $model->load('sections');
            $hero = $model->sections->first(fn ($section) => $section->key === 'hero' && $section->is_active
                && $section->type === ($this->definitions->sections($model->key)['hero'] ?? null));
            $page['heading'] = $hero?->title ?: $model->title;
            $page['title'] = $model->meta_title ?: '';
            $page['description'] = $model->meta_description ?: '';
            $page['sections'] = $this->sections($model);
            $page['intro'] = $page['sections'][0]['text'] ?? '';
            if ($path === '/gallery') {
                $page['items'] = GalleryItem::where('is_active', true)->where('published_at', '<=', now())->orderBy('sort_order')->orderBy('id')->limit(24)->get()
                    ->map(fn (GalleryItem $item): array => ['label' => $item->title ?: 'معرض الصور', 'href' => null, 'image' => $this->image($item->image, config('filesystems.media_disk', 'public'))])->all();
            }
        }
        $page['title'] = $page['title'] ?: $page['heading'].' | '.$brand;
        $page['description'] = $page['description'] ?: Str::limit($page['intro'] ?: $page['heading'].' من '.$brand.' في تبوك.', 155);
        if ($path === '/menu' || $path === '/grills-tabuk' || str_starts_with($path, '/menu/category/')) {
            $page['schema'] = [
                '@context' => 'https://schema.org',
                '@type' => str_starts_with($path, '/menu/category/') ? 'MenuSection' : 'Menu',
                '@id' => $this->url($path).'#menu', 'url' => $this->url($path),
                'name' => $page['heading'], 'description' => $page['description'],
                'hasMenuItem' => array_map(fn (array $item): array => $this->menuItem($item['label'], $item['href'], $item['text'], $item['image'] ?? null), $page['items']),
            ];
        }

        return $page;
    }

    private function sections(Page $page): array
    {
        $definitions = $this->definitions->sections($page->key);

        return $page->sections->filter(fn ($section): bool => $section->is_active && ($definitions[$section->key] ?? null) === $section->type)
            ->map(fn ($section): array => ['title' => $section->title ?? '', 'text' => $section->content ?: ($section->subtitle ?? '')])
            ->filter(fn (array $section): bool => $section['title'] !== '' || $section['text'] !== '')->values()->all();
    }

    private function products(bool $featured = false, ?array $categoryIds = null, bool $withImages = false): array
    {
        return Product::visibleInMenu()->when($featured, fn ($query) => $query->where('is_featured', true)->limit(6))
            ->when($categoryIds !== null, fn (Builder $query) => $query->whereIn('category_id', $categoryIds))
            ->when($withImages, fn (Builder $query) => $query->with(['primaryImage', 'images']))
            ->orderBy('sort_order')->orderBy('id')->get(['id', 'uuid', 'name', 'short_description', 'description'])
            ->map(function (Product $product) use ($withImages): array {
                $image = $withImages ? ($product->primaryImage ?? $product->images->first()) : null;

                return ['label' => $product->name, 'href' => '/product/'.$product->uuid,
                    'text' => $product->short_description ?: ($product->description ?: ''),
                    'image' => $image ? $this->image($image->path, $image->disk) : null];
            })->all();
    }

    private function categoryLinks(iterable $categories): array
    {
        return collect($categories)->map(fn (Category $category): array => ['label' => $category->name, 'href' => '/menu/category/'.$category->slug])->all();
    }

    private function delivery(): array
    {
        $branches = Branch::where('is_active', true)->where('accepts_orders', true)->where('supports_delivery', true)
            ->get(['name', 'city'])->map(fn (Branch $branch): string => implode('، ', array_filter([$branch->name, $branch->city])))->all();
        $settings = $this->settings->all();
        $enabled = $settings['delivery.enabled'] && $settings['orders.enabled'] && ! $settings['orders.maintenance_mode'] && $branches !== [];

        return [[
            'title' => 'التوصيل والاستلام',
            'text' => $enabled
                ? 'الفروع المهيأة للتوصيل: '.implode('؛ ', $branches).'. تحقّق من التغطية والرسوم لعنوانك عند الطلب.'
                : 'التوصيل غير متاح حاليًا عبر الموقع. راجع المنيو لمعرفة خيارات الاستلام حسب الفرع.',
        ]];
    }

    private function url(string $path): string
    {
        return rtrim(config('seo.site_url'), '/').$path;
    }

    private function menuItem(string $name, string $path, string $description, ?string $image): array
    {
        return array_filter([
            '@type' => 'MenuItem', '@id' => $this->url($path).'#dish', 'url' => $this->url($path),
            'name' => $name, 'description' => $description,
            'image' => $image && str_starts_with($image, '/') ? $this->url($image) : $image,
        ]);
    }

    private function restaurant(): array
    {
        $restaurant = $this->settings->publicSettings()['restaurant'];
        $address = array_filter(['addressLocality' => $restaurant['city'] ?? null, 'addressCountry' => $restaurant['country'] ?? null]);

        return array_filter([
            '@context' => 'https://schema.org', '@type' => 'Restaurant', '@id' => $this->url('/').'#restaurant',
            'name' => $restaurant['name'], 'url' => $this->url('/'), 'hasMenu' => $this->url('/menu'),
            'servesCuisine' => ['مشويات', 'دجاج تركي'],
            'image' => $this->url('/apple-touch-icon.png'), 'telephone' => $restaurant['phone'] ?? null,
            'address' => $address ? ['@type' => 'PostalAddress', ...$address] : null,
        ]);
    }

    private function image(?string $path, string $disk): ?string
    {
        if (! $path) {
            return null;
        }
        $url = $this->media->url($path, $disk);

        return preg_match('~^(?:https?://[^/]+/|/(?!/))~i', $url) && ! preg_match('/[\\\\\x00-\x20]/', $url) ? $url : null;
    }
}
