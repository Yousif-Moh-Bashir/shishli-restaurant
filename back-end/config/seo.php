<?php

return [
    'site_url' => env('SITE_URL', 'https://shishlifresh.com'),
    'prerender' => env('SEO_PRERENDER', false),
    'frontend_html' => env('SEO_FRONTEND_HTML', resource_path('frontend/index.html')),
];
