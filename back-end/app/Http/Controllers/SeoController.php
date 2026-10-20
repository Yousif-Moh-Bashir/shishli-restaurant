<?php

namespace App\Http\Controllers;

use App\Enums\PageStatus;
use App\Models\Page;
use App\Models\Product;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SeoController extends Controller
{
    public function sitemap(): StreamedResponse
    {
        $base = rtrim(config('seo.site_url'), '/');

        return response()->stream(function () use ($base): void {
            $entry = function (string $path, ?string $modified = null) use ($base): void {
                echo '<url><loc>'.htmlspecialchars($base.$path, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</loc>';
                if ($modified !== null) {
                    echo '<lastmod>'.$modified.'</lastmod>';
                }
                echo '</url>';
            };
            echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
            foreach (['/', '/menu', '/branches', '/offers'] as $path) {
                $entry($path);
            }
            foreach (Page::query()->whereIn('key', ['about', 'contact', 'gallery'])
                ->where('status', PageStatus::Published)
                ->where(fn ($query) => $query->whereNull('published_at')->orWhere('published_at', '<=', now()))
                ->orderBy('id')->cursor() as $page) {
                $entry('/'.$page->key, $page->updated_at?->toAtomString());
            }
            foreach (Product::query()->visibleInMenu()->orderBy('id')->cursor() as $product) {
                $entry('/product/'.$product->uuid, $product->updated_at?->toAtomString());
            }
            echo '</urlset>';
        }, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    public function robots(): Response
    {
        $lines = ['User-agent: *'];
        foreach (['/admin', '/driver', '/account', '/orders', '/cart', '/checkout', '/login', '/register', '/order-success', '/track-order', '/forbidden', '/api/v1/admin/', '/api/user'] as $path) {
            $lines[] = 'Disallow: '.$path;
        }
        $lines[] = 'Sitemap: '.rtrim(config('seo.site_url'), '/').'/sitemap.xml';

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
