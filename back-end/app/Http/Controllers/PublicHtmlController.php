<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Services\PublicPageContent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Throwable;

class PublicHtmlController extends Controller
{
    public function catalog(string $catalogPath, PublicPageContent $content): JsonResponse
    {
        $page = $content->get('/'.$catalogPath);
        abort_if($page === null, 404);

        return ApiResponse::success($page)->header('Cache-Control', 'no-store');
    }

    public function __invoke(Request $request, PublicPageContent $content): Response
    {
        $path = $request->getPathInfo();
        $canonicalPath = strtolower(rtrim($path, '/')) ?: '/';
        $prerender = config('seo.prerender');

        try {
            $page = $prerender ? $content->get($canonicalPath) : null;
            if ($prerender ? $page === null : ! $content->isPublic($canonicalPath)) {
                return response(view('seo.unavailable', ['message' => 'الصفحة غير موجودة']), 404, ['Cache-Control' => 'no-store']);
            }
            if ($path !== $canonicalPath) {
                $query = $request->getQueryString();

                return response('', 301, ['Location' => $canonicalPath.($query ? '?'.$query : ''), 'Cache-Control' => 'no-store']);
            }
            if (! $prerender) {
                return response('', 200, ['X-Accel-Redirect' => '/index.html']);
            }
            $file = config('seo.frontend_html');
            $shell = is_readable($file) ? file_get_contents($file) : false;
            if ($shell === false || ! str_contains($shell, '<div id="app"></div>')) {
                throw new \RuntimeException('Prerender requires the matching Vite HTML artifact.');
            }
            $canonical = rtrim(config('seo.site_url'), '/').$page['path'];
            $head = view('seo.head', compact('page', 'canonical'))->render();
            $shell = preg_replace('~<title>[^<]*</title>|<meta\s+(?:name="(?:description|twitter:title|twitter:description)"|property="og:(?:title|description)")\s+[^>]*>~i', '', $shell);
            $snapshot = preg_replace('~>\s+<~', '><', view('seo.snapshot', ['page' => Arr::except($page, ['schema'])])->render());
            $html = str_replace(['</head>', '<div id="app"></div>'], [$head.'</head>', $snapshot.'<div id="app"></div>'], $shell);

            return response($html, 200, ['Cache-Control' => 'no-store']);
        } catch (Throwable $error) {
            report($error);

            return response(view('seo.unavailable', ['message' => 'الصفحة غير متاحة مؤقتًا']), 503, ['Cache-Control' => 'no-store', 'Retry-After' => '60']);
        }
    }
}
